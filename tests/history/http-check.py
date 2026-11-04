import concurrent.futures
import html
import http.cookiejar
import json
import os
import re
import threading
import urllib.error
import urllib.parse
import urllib.request

# These requests write only to named synthetic accounts on the disposable local instance.
assert os.environ.get('HISTORY_TEST_ENVIRONMENT') == '1'
base = os.environ.get('HISTORY_TEST_URL', 'http://localhost:8136')
assert base in ['http://localhost:8136', 'http://localhost:8137']
password = os.environ['HISTORY_TEST_PASSWORD']
checks = 0

def verify(actual, expected, description):
	global checks
	checks += 1
	if actual != expected:
		raise AssertionError(f'{description}: actual={actual!r}, expected={expected!r}')

class Client:
	def __init__(self, user, secret):
		assert user.startswith('history-')
		self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
		self.token = None
		status, page = self.call('/login', raw=True)
		self.token = html.unescape(re.search(r'data-requesttoken="([^"]+)"', page).group(1))
		self.call('/login', 'POST', {'user': user, 'password': secret, 'requesttoken': self.token}, raw=True, form=True)
		status, self.page = self.call('/apps/timesheet/', raw=True)
		assert status == 200 and 'id="timesheet-app"' in self.page, 'Authenticated test application unavailable'
		self.token = html.unescape(re.search(r'data-requesttoken="([^"]+)"', self.page).group(1))

	def call(self, path, method='GET', payload=None, csrf=True, raw=False, form=False):
		headers = {'Accept': 'text/html' if raw else 'application/json', 'Origin': base}
		if csrf and self.token:
			headers['requesttoken'] = self.token
		data = None
		if payload is not None:
			data = urllib.parse.urlencode(payload).encode() if form else json.dumps(payload).encode()
			headers['Content-Type'] = 'application/x-www-form-urlencoded' if form else 'application/json'
		try:
			response = self.opener.open(urllib.request.Request(base + path, method=method, data=data, headers=headers), timeout=40)
		except urllib.error.HTTPError as error:
			response = error
		with response:
			body = response.read().decode('utf-8')
			if raw:
				return response.status, body
			try:
				return response.status, json.loads(body)
			except ValueError:
				return response.status, None

hr_a = Client('history-hr-a', password)
hr_b = Client('history-hr-b', password)
employee = Client('history-employee-a', password)
combined = Client('history-hr-admin', password)
verify('data-tab="change-history"' in hr_a.page, True, 'HR sees the third tab')
verify('data-tab="change-history"' in employee.page, False, 'Employees do not receive the tab')
verify('data-tab="change-history"' in combined.page, True, 'Combined HR/admin sees its scoped tab')
verify(hr_a.call('/apps/timesheet/api/hr/change-history', csrf=False)[0], 200, 'Read-only history supports native GET without a write token')
verify(employee.call('/apps/timesheet/api/hr/change-history')[0], 403, 'An employee cannot call the HR history endpoint')
if os.environ.get('HISTORY_TEST_ADMIN_PASSWORD'):
	admin = Client('history-admin', os.environ['HISTORY_TEST_ADMIN_PASSWORD'])
	verify('data-tab="change-history"' in admin.page, False, 'An admin without HR assignment does not receive the tab')
	verify(admin.call('/apps/timesheet/api/hr/change-history')[0], 403, 'Admin privileges alone do not bypass history scope')

for path in [
	'/api/hr/change-history?employee=history-other', '/api/entries?user=history-other',
	'/api/hr/config/history-other', '/api/rules/effective/history-other',
	'/api/entries/export-xlsx?user=history-other',
]:
	verify(hr_a.call('/apps/timesheet' + path)[0], 403, 'Foreign HR data is rejected: ' + path)
verify(hr_a.call('/apps/timesheet/api/hr/config/history-other', 'PUT', {'dailyMin': 480, 'state': 'HE'})[0], 403, 'Foreign work setting writes are rejected')
verify(employee.call('/apps/timesheet/api/hr/config/history-employee-a')[0], 200, 'Self work settings remain readable')
verify(hr_a.call('/apps/timesheet/api/hr/config/history-employee-a', 'PUT', {'dailyMin': 480, 'state': 'BY'}, csrf=False)[0], 412, 'Work settings restore native CSRF enforcement')
for query in ['from=2026-02-30', 'from=2026-11-04&to=2026-11-01', 'limit=51', 'beforeId=-1', 'kind=unknown', 'actor=history-hr-other', 'actorDeleted=1&actor=history-hr-a']:
	verify(hr_a.call('/apps/timesheet/api/hr/change-history?' + query)[0], 400, 'Invalid or unscoped history filter is rejected: ' + query)

existing = hr_a.call('/apps/timesheet/api/entries?user=history-employee-a&from=2026-11-01&to=2026-11-01')[1]
for entry in existing:
	verify(hr_a.call('/apps/timesheet/api/entries/' + str(entry['id']), 'DELETE')[0], 200, 'Only the named disposable test entry is reset')
status, created = hr_a.call('/apps/timesheet/api/entries', 'POST', {
	'user': 'history-employee-a', 'workDate': '2026-11-01', 'start': '08:00', 'end': '16:30', 'breakMinutes': 30,
	'comment': 'Initial concurrent value', 'actor': 'history-hr-other',
})
verify(status, 200, 'A scoped HR account can create a time entry')
entry_id = created['id']
verify(hr_a.call('/apps/timesheet/api/entries', 'POST', {'user': 'history-employee-a', 'workDate': '2026-11-01', 'start': '08:00', 'end': '16:30', 'breakMinutes': 30, 'comment': 'Conflict'})[0], 409, 'A duplicate complete entry does not create a successful write')
status, history = hr_a.call('/apps/timesheet/api/hr/change-history?employee=history-employee-a')
verify(history['items'][0]['actor']['id'], 'history-hr-a', 'The client cannot forge the editor')
before_count = len(history['items'])

# Independent authenticated requests contend for the same employee and must form one old/new chain.
barrier = threading.Barrier(2)
def simultaneous_update(arguments):
	client, value = arguments
	barrier.wait(timeout=15)
	return client.call('/apps/timesheet/api/entries/' + str(entry_id), 'PUT', {'comment': value})
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
	results = list(pool.map(simultaneous_update, [(hr_a, 'Concurrent A'), (hr_b, 'Concurrent B')]))
verify([result[0] for result in results], [200, 200], 'Both simultaneous HR saves complete successfully')
history = hr_a.call('/apps/timesheet/api/hr/change-history?employee=history-employee-a')[1]
verify(len(history['items']), before_count + 2, 'Both real changes produce one event each')
latest, previous = history['items'][:2]
verify({latest['actor']['id'], previous['actor']['id']}, {'history-hr-a', 'history-hr-b'}, 'Both authenticated editors are recorded')
verify(previous['changes'][0]['before'], 'Initial concurrent value', 'The first concurrent write reads the original database value')
verify(latest['changes'][0]['before'], previous['changes'][0]['after'], 'The second concurrent write reads the first committed value')

verify(hr_a.call('/apps/timesheet/api/entries/' + str(entry_id), 'PUT', {'comment': latest['changes'][0]['after']})[0], 200, 'An identical retry still returns success')
verify(len(hr_a.call('/apps/timesheet/api/hr/change-history?employee=history-employee-a')[1]['items']), len(history['items']), 'An identical HTTP retry creates no extra event')
verify(employee.call('/apps/timesheet/api/entries/' + str(entry_id), 'PUT', {'comment': 'Employee self change'})[0], 200, 'Employees can still change their own entries')
verify(len(hr_a.call('/apps/timesheet/api/hr/change-history?employee=history-employee-a')[1]['items']), len(history['items']), 'Employee HTTP self-edits stay outside HR history')
verify(hr_b.call('/apps/timesheet/api/entries/' + str(entry_id), 'PUT', {'comment': 'Final HR explanation'})[0], 200, 'A later HR edit is recorded normally')
latest = hr_a.call('/apps/timesheet/api/hr/change-history?employee=history-employee-a')[1]['items'][0]
verify(latest['changes'][0]['before'], 'Employee self change', 'A later HR event uses the intervening employee value')
verify(hr_a.call('/apps/timesheet/api/entries/' + str(entry_id), 'DELETE')[0], 200, 'HR can delete a scoped entry')
latest = hr_a.call('/apps/timesheet/api/hr/change-history?employee=history-employee-a')[1]['items'][0]
verify(latest['kind'], 'deleted', 'Deleted HTTP entries remain identifiable in history')
verify(latest['entryId'], entry_id, 'The deleted entry ID survives')
verify('history-other' in json.dumps(history['filters']), False, 'Filter options contain no foreign employee or editor')
print(json.dumps({'historyHttpChecks': checks, 'result': 'passed'}))
