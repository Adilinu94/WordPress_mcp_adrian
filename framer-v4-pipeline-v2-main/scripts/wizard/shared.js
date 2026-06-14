/**
 * scripts/wizard/shared.js — Shared Helpers for Wizard Sub-Commands
 *
 * Sprint 6 Refactoring: Extracted from wizard.js (905 lines → modular).
 * All sub-command modules import log, path-helpers, and recovery utilities
 * from this shared module.
 *
 * ENH-16: spawnWithRetry — handles Windows npm.cmd vs npm (bash compat)
 */

import readline from 'readline/promises';
import { stdin as input, stdout as output } from 'process';
import { execFile } from 'child_process';
import { promisify } from 'util';
import fs from 'fs/promises';
import { existsSync } from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const execFileAsync = promisify(execFile);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const repoDir = path.resolve(__dirname, '..', '..');
export const pipelineDir = path.resolve(__dirname, '..');
export const nodeBin = process.execPath;
// On Windows, .cmd is needed for execFile in cmd.exe.
// In bash/WSL/Git-Bash, .cmd causes EINVAL → retry without .cmd.
export const npxBin = process.platform === 'win32' ? 'npx.cmd' : 'npx';
export const npmBin = process.platform === 'win32' ? 'npm.cmd' : 'npm';

/**
 * Spawns a command, retrying with alternate binary names on Windows.
 * Escalation: .cmd → bare name → shell:true (handles bash/WSL/Git-Bash).
 */
async function spawnWithRetry(command, args, options) {
  try {
    return await execFileAsync(command, args, options);
  } catch (err) {
    if (process.platform !== 'win32') throw err;
    const isSpawnErr = err.code === 'EINVAL' || err.code === 'ENOENT';
    if (!isSpawnErr) throw err;

    const isCmdExt = command.endsWith('.cmd');

    // Step 1: try alternate extension
    try {
      const altCmd = isCmdExt ? command.replace(/\.cmd$/, '') : command + '.cmd';
      return await execFileAsync(altCmd, args, options);
    } catch (err2) {
      if (err2.code !== 'EINVAL' && err2.code !== 'ENOENT') throw err2;
    }

    // Step 2: last resort — shell:true for Git Bash/MSYS2 compat
    return await execFileAsync(command, args, { ...options, shell: true });
  }
}

/** Readline interface shared across interactive commands */
export function createRl() {
  return readline.createInterface({ input, output });
}

/** Colored console output */
export const log = {
  info: (msg) => console.log(`\n🔵 [INFO] ${msg}`),
  success: (msg) => console.log(`\n✅ [SUCCESS] ${msg}`),
  warn: (msg) => console.log(`\n⚠️  [WARN] ${msg}`),
  error: (msg) => console.log(`\n❌ [ERROR] ${msg}`),
  step: (msg) => console.log(`\n▶️  [STEP] ${msg}`),
};

/**
 * Findet das Workspace-Root-Verzeichnis.
 * Prüft FRAMER_PIPELINE_ROOT env var, cwd, repoDir, und parent dir.
 *
 * @returns {string} Absoluter Pfad zum Workspace-Root
 */
export function findWorkspaceRoot() {
  if (process.env.FRAMER_PIPELINE_ROOT) return path.resolve(process.env.FRAMER_PIPELINE_ROOT);
  const candidates = [
    process.cwd(),
    repoDir,
    path.resolve(repoDir, '..'),
  ];
  return candidates.find(dir =>
    existsSync(path.join(dir, 'tools', 'framer-export')) ||
    existsSync(path.join(dir, 'FramerExport')) ||
    existsSync(path.join(dir, 'build-manifest.json'))
  ) || repoDir;
}

/**
 * Findet das FramerExport-Verzeichnis.
 *
 * @param {string} rootDir - Workspace-Root
 * @returns {string|null} Pfad zum FramerExport-Verzeichnis oder null
 */
export function findFramerExportDir(rootDir) {
  const candidates = [
    process.env.FRAMER_EXPORT_DIR,
    path.join(rootDir, 'tools', 'framer-export'),
    path.join(rootDir, 'FramerExport'),
    path.resolve(rootDir, '..', 'FramerExport'),
  ].filter(Boolean).map(p => path.resolve(p));
  return candidates.find(dir => existsSync(dir)) || null;
}

/**
 * Führt einen Shell-Befehl aus und loggt den Fortschritt.
 * ENH-16: Uses spawnWithRetry for cross-platform binary resolution.
 *
 * @param {string} command - Auszuführender Befehl
 * @param {Array<string>} args - Befehlsargumente
 * @param {string} description - Beschreibung für Logging
 * @param {string} [cwd] - Working directory
 * @returns {Promise<string>} stdout
 * @throws {Error} Bei fehlgeschlagenem Befehl
 */
export async function runFile(command, args, description, cwd = null) {
  const workDir = cwd || findWorkspaceRoot();
  log.step(description);
  try {
    const { stdout, stderr } = await spawnWithRetry(command, args, {
      cwd: workDir,
      maxBuffer: 1024 * 1024 * 20,
    });
    if (stderr) log.warn(stderr);
    log.success(`${description} abgeschlossen.`);
    return stdout;
  } catch (error) {
    log.error(`${description} fehlgeschlagen.`);
    console.error(error.message);
    throw error;
  }
}

/**
 * Findet alle Verzeichnisse die eine index.html enthalten (rekursiv, max Tiefe 3).
 *
 * @param {string} baseDir - Basisverzeichnis
 * @returns {Promise<Array<{dir: string, mtimeMs: number}>>} Sortiert nach mtime (neueste zuerst)
 */
export async function findIndexHtmlDirs(baseDir) {
  const found = [];
  async function scan(dir, depth = 0) {
    if (depth > 3) return;
    if (!existsSync(dir)) return;
    const entries = await fs.readdir(dir, { withFileTypes: true });
    if (entries.some(e => e.isFile() && e.name === 'index.html')) {
      const stat = await fs.stat(path.join(dir, 'index.html'));
      found.push({ dir, mtimeMs: stat.mtimeMs });
    }
    for (const entry of entries) {
      if (entry.isDirectory() && !entry.name.startsWith('.') && entry.name !== 'node_modules') {
        await scan(path.join(dir, entry.name), depth + 1);
      }
    }
  }
  await scan(baseDir);
  return found.sort((a, b) => b.mtimeMs - a.mtimeMs);
}

/**
 * Liest eine JSON-Datei wenn sie existiert.
 *
 * @param {string} filePath
 * @returns {Promise<object|null>}
 */
export async function readJsonIfExists(filePath) {
  if (!existsSync(filePath)) return null;
  return JSON.parse(await fs.readFile(filePath, 'utf8'));
}

/**
 * Interaktive Error-Recovery-Prompt.
 * Bietet [R]etry, [S]kip, [F]ix, [A]bort.
 *
 * @param {string} stepName - Name des fehlgeschlagenen Schritts
 * @param {Error|string} error - Fehler
 * @param {object} rl - Readline-Interface
 * @returns {Promise<'retry'|'skip'>}
 */
export async function promptErrorRecovery(stepName, error, rl) {
  console.log(`\n${'─'.repeat(56)}`);
  console.log(`  ⚡ FEHLER in Schritt: ${stepName}`);
  console.log(`  ${error.message || error}`);
  console.log(`${'─'.repeat(56)}`);
  console.log('  [R]etry — Schritt wiederholen');
  console.log('  [S]kip  — Schritt überspringen und fortsetzen');
  console.log('  [F]ix   — Manuell beheben, dann weitermachen');
  console.log('  [A]bort — Build abbrechen');

  while (true) {
    const choice = (await rl.question('  Auswahl [R/S/F/A]: ')).trim().toLowerCase();
    switch (choice) {
      case 'r': return 'retry';
      case 's': log.warn(`Schritt "${stepName}" übersprungen.`); return 'skip';
      case 'f':
        log.info('Warte auf manuelle Behebung... (Enter zum Fortfahren)');
        await rl.question('');
        return 'retry';
      case 'a':
        log.error('Build durch Benutzer abgebrochen.');
        rl.close();
        process.exit(1);
      default:
        console.log('  Ungültige Eingabe. [R]etry [S]kip [F]ix [A]bort');
    }
  }
}

/**
 * Führt eine Funktion mit interaktivem Error-Recovery aus.
 * Wiederholt bei 'retry', überspringt bei 'skip'.
 *
 * @param {string} stepName
 * @param {Function} fn - Async-Funktion
 * @param {object} rl - Readline-Interface
 * @returns {Promise<void>}
 */
export async function runWithRecovery(stepName, fn, rl) {
  while (true) {
    try {
      await fn();
      return;
    } catch (err) {
      const action = await promptErrorRecovery(stepName, err, rl);
      if (action === 'skip') return;
    }
  }
}
