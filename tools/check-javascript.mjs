import { readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const projectRoot = fileURLToPath(new URL('../', import.meta.url));

function findJavaScript(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const filename = join(directory, entry.name);
    if (entry.isDirectory()) return findJavaScript(filename);
    return entry.isFile() && entry.name.endsWith('.js') ? [filename] : [];
  });
}

const files = ['js', 'l10n']
  .flatMap((directory) => findJavaScript(join(projectRoot, directory)))
  .sort();

let failures = 0;
for (const filename of files) {
  const result = spawnSync(process.execPath, ['--check', filename], {
    cwd: projectRoot,
    encoding: 'utf8',
    windowsHide: true,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    failures += 1;
    process.stderr.write(`${relative(projectRoot, filename)}\n${result.stderr}`);
  }
}

if (failures > 0) {
  process.stderr.write(`JavaScript syntax errors: ${failures} file(s).\n`);
  process.exitCode = 1;
} else {
  console.log(`JavaScript syntax valid (${files.length} files).`);
}
