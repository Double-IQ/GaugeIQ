<?php
declare(strict_types=1);

final class GaugeIQUpdater
{
    private string $root;
    private string $storage;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->storage = $this->root . '/storage/update';
    }


    /**
     * Retrieve the latest published stable GitHub Release and its expected assets.
     * Asset names and URLs are derived from the tag, never accepted from the browser.
     */
    public function latestReleaseInfo(): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension is required to check GaugeIQ releases.');
        }

        $url = 'https://api.github.com/repos/Double-IQ/GaugeIQ/releases/latest';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'GaugeIQ-Updater',
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            throw new RuntimeException(
                'Unable to check the latest GaugeIQ release.' .
                ($error !== '' ? ' ' . $error : ' GitHub returned HTTP ' . $status . '.')
            );
        }

        $release = json_decode((string)$body, true);
        if (!is_array($release) || !is_array($release['assets'] ?? null)) {
            throw new RuntimeException('GitHub returned an invalid GaugeIQ release response.');
        }

        $tag = (string)($release['tag_name'] ?? '');
        if (!preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?)$/', $tag, $match)) {
            throw new RuntimeException('The latest GaugeIQ release has an invalid version tag.');
        }
        if (!empty($release['draft']) || !empty($release['prerelease'])) {
            throw new RuntimeException('The latest GaugeIQ release is not a stable published release.');
        }

        $version = $match[1];
        $expectedPackageName = 'GaugeIQ-' . $version . '.zip';
        $expectedChecksumName = $expectedPackageName . '.sha256';
        $expectedBaseUrl = 'https://github.com/Double-IQ/GaugeIQ/releases/download/' . $tag . '/';
        $packageUrl = null;
        $checksumUrl = null;

        foreach ($release['assets'] as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string)($asset['name'] ?? '');
            $assetUrl = (string)($asset['browser_download_url'] ?? '');
            if ($name === $expectedPackageName && $assetUrl === $expectedBaseUrl . $expectedPackageName) {
                $packageUrl = $assetUrl;
            } elseif ($name === $expectedChecksumName && $assetUrl === $expectedBaseUrl . $expectedChecksumName) {
                $checksumUrl = $assetUrl;
            }
        }

        return [
            'latest_version' => $version,
            'release_tag' => $tag,
            'release_url' => (string)($release['html_url'] ?? 'https://github.com/Double-IQ/GaugeIQ/releases'),
            'package_url' => $packageUrl,
            'checksum_url' => $checksumUrl,
            'assets_ready' => $packageUrl !== null && $checksumUrl !== null,
        ];
    }

    /**
     * Re-resolve the current latest release on the server before installing.
     * This prevents tampered hidden fields or stale browser data from selecting
     * arbitrary package URLs or an older release.
     */
    public function installRelease(string $expectedVersion): array
    {
        require_once $this->root . '/app/Version.php';
        $release = $this->latestReleaseInfo();
        $version = ltrim(trim($expectedVersion), 'v');

        if ($version === '') {
            throw new RuntimeException('No release version was selected.');
        }
        if (!hash_equals($release['latest_version'], $version)) {
            throw new RuntimeException('The latest GaugeIQ release changed. Refresh Admin and try again.');
        }
        if (!version_compare($release['latest_version'], GaugeIQVersion::VERSION, '>')) {
            throw new RuntimeException('GaugeIQ is already at the latest published stable release.');
        }
        if (!$release['assets_ready']) {
            throw new RuntimeException('The latest GaugeIQ release package or checksum is not ready yet.');
        }

        return $this->installLatest(
            $release['latest_version'],
            $release['package_url'],
            $release['checksum_url']
        );
    }

    public function installLatest(string $expectedVersion, string $packageUrl, string $checksumUrl): array
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('The PHP ZIP extension is required for updates.');
        }

        $version = ltrim($expectedVersion, 'v');
        if ($version === '' || !preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*$/', $version)) {
            throw new RuntimeException('Invalid release version.');
        }

        $expectedAssetBase = 'https://github.com/Double-IQ/GaugeIQ/releases/download/v' . $version . '/GaugeIQ-' . $version;
        if ($packageUrl !== $expectedAssetBase . '.zip' || $checksumUrl !== $expectedAssetBase . '.zip.sha256') {
            throw new RuntimeException('The package URLs do not match the official GaugeIQ release assets.');
        }

        $this->prepareWorkspace();
        $stage = $this->storage . '/stage';
        try {
        $zipPath = $this->storage . '/GaugeIQ-' . $version . '.zip';
        $checksumPath = $zipPath . '.sha256';
        $this->download($packageUrl, $zipPath);
        $this->download($checksumUrl, $checksumPath);

        $expectedHash = $this->parseChecksum((string)file_get_contents($checksumPath));
        $actualHash = hash_file('sha256', $zipPath);
        if (!hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
            throw new RuntimeException('The downloaded release checksum does not match.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The release package could not be opened.');
        }

        if (is_dir($stage)) {
            $this->removeTree($stage);
        }
        mkdir($stage, 0750, true);

        $prefix = $this->validateArchive($zip, $version);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || !str_starts_with($name, $prefix)) {
                $zip->close();
                throw new RuntimeException('The release package contains an entry outside its expected root folder.');
            }
            if (str_ends_with($name, '/')) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
                $zip->close();
                throw new RuntimeException('The release package contains an unsafe path.');
            }
            foreach (explode('/', $relative) as $part) {
                if ($part === '..' || $part === '') {
                    $zip->close();
                    throw new RuntimeException('The release package contains an unsafe path.');
                }
            }
            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) &&
                $opsys === ZipArchive::OPSYS_UNIX &&
                ((($attributes >> 16) & 0170000) === 0120000)) {
                $zip->close();
                throw new RuntimeException('The release package contains an unsupported symbolic link.');
            }
            $target = $stage . '/' . $relative;
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException('The release package contains an unreadable file.');
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                $zip->close();
                throw new RuntimeException('Unable to stage the release package.');
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
        }
        $zip->close();

        $backup = $this->storage . '/backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!mkdir($backup, 0750, true) && !is_dir($backup)) {
            throw new RuntimeException('Unable to create the update backup.');
        }
        $this->backupApplication($backup);
        $databaseBackup = $this->backupDatabase($backup);

        try {
            $this->swapApplication($stage);
            $this->runDatabaseMigration();
            $this->healthCheck($version);
        } catch (Throwable $e) {
            try {
                $this->restoreApplication($backup);
            } catch (Throwable $rollbackError) {
                error_log('GaugeIQ application rollback failed: ' . $rollbackError->getMessage());
            }
            if (is_array($databaseBackup)) {
                try {
                    $this->restoreDatabase($databaseBackup);
                } catch (Throwable $databaseRollbackError) {
                    error_log('GaugeIQ database rollback failed: ' . $databaseRollbackError->getMessage());
                }
            }
            throw $e;
        }

        $this->pruneBackups(3);
        return ['version' => $version];
        } finally {
            $this->removeTree($stage);
            $this->releaseLock();
        }
    }

    private function validateArchive(ZipArchive $zip, string $version): string
    {
        $prefix = 'GaugeIQ-' . $version . '/';
        $required = ['app/', 'public/', 'composer.json', 'vendor/autoload.php', 'vendor/minishlink/web-push/'];
        $found = array_fill_keys($required, false);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || !str_starts_with($name, $prefix)) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')) {
                throw new RuntimeException('The release package contains an unsafe path.');
            }
            foreach (explode('/', $relative) as $part) {
                if ($part === '..' || $part === '') {
                    throw new RuntimeException('The release package contains an unsafe path.');
                }
            }
            foreach ($required as $item) {
                if ($item === 'vendor/autoload.php') {
                    if ($relative === $item) {
                        $found[$item] = true;
                    }
                    continue;
                }
                if ($relative === $item || str_starts_with($relative, $item)) {
                    $found[$item] = true;
                }
            }
        }

        foreach ($found as $item => $ok) {
            if (!$ok) {
                throw new RuntimeException('The release package is missing required application files.');
            }
        }
        return $prefix;
    }

    private function backupApplication(string $backup): void
    {
        foreach (array_diff(scandir($this->root) ?: [], ['.', '..', 'storage']) as $entry) {
            if ($entry === 'config') {
                $this->backupConfig($backup . '/config');
                continue;
            }
            $source = $this->root . '/' . $entry;
            $target = $backup . '/' . $entry;
            if (is_dir($source)) {
                $this->copyTree($source, $target);
            } elseif (is_file($source)) {
                copy($source, $target);
            }
        }
    }

    private function backupConfig(string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0750, true);
        }
        $source = $this->root . '/config';
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $entry) {
            if (in_array($entry, ['local.php', 'installed.lock'], true)) {
                continue;
            }
            $this->copyTree($source . '/' . $entry, $target . '/' . $entry);
        }
    }

    private function restoreApplication(string $backup): void
    {
        foreach (array_diff(scandir($this->root) ?: [], ['.', '..', 'storage']) as $entry) {
            if ($entry === 'config') {
                continue;
            }
            $path = $this->root . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        foreach (array_diff(scandir($backup) ?: [], ['.', '..']) as $entry) {
            if ($entry === '.database') {
                continue;
            }
            $this->copyTree($backup . '/' . $entry, $this->root . '/' . $entry);
        }

        $backupConfig = $backup . '/config';
        if (is_dir($backupConfig)) {
            foreach (array_diff(scandir($backupConfig) ?: [], ['.', '..']) as $entry) {
                $this->copyTree($backupConfig . '/' . $entry, $this->root . '/config/' . $entry);
            }
        }
    }

    private function copyTree(string $source, string $target): void
    {
        if (is_dir($source)) {
            if (!is_dir($target)) {
                mkdir($target, 0750, true);
            }
            foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $entry) {
                $this->copyTree($source . '/' . $entry, $target . '/' . $entry);
            }
            return;
        }
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0750, true);
        }
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to create the update backup.');
        }
    }

    private function swapApplication(string $stage): void
    {
        $preserve = ['config/local.php', 'config/installed.lock', 'storage'];
        $entries = array_diff(scandir($stage) ?: [], ['.', '..']);

        foreach ($entries as $entry) {
            if (in_array($entry, ['config', 'storage'], true)) {
                continue;
            }
            $this->replacePath($stage . '/' . $entry, $this->root . '/' . $entry);
        }

        foreach (['config', 'public', 'app', 'bin', 'cron', 'database'] as $dir) {
            $source = $stage . '/' . $dir;
            if (!is_dir($source)) {
                continue;
            }
            if ($dir === 'config') {
                foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $file) {
                    if (in_array('config/' . $file, $preserve, true)) {
                        continue;
                    }
                    $this->replacePath($source . '/' . $file, $this->root . '/config/' . $file);
                }
            } else {
                $this->replaceDirectory($source, $this->root . '/' . $dir);
            }
        }
    }

    private function replaceDirectory(string $source, string $target): void
    {
        if (is_dir($target)) {
            $this->removeTree($target);
        } elseif (file_exists($target)) {
            unlink($target);
        }
        mkdir($target, 0750, true);
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $entry) {
            $this->replacePath($source . '/' . $entry, $target . '/' . $entry);
        }
    }

    private function replacePath(string $source, string $target): void
    {
        $parent = dirname($target);
        if (!is_dir($parent)) {
            mkdir($parent, 0750, true);
        }
        if (is_dir($source)) {
            $this->replaceDirectory($source, $target);
            return;
        }
        if (file_exists($target)) {
            unlink($target);
        }
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to install a release file.');
        }
        @chmod($target, 0640);
    }

    private function download(string $url, string $target): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) {
            throw new RuntimeException('The release download URL is invalid.');
        }
        $parts = parse_url($url);
        if (($parts['host'] ?? '') !== 'github.com' && ($parts['host'] ?? '') !== 'objects.githubusercontent.com') {
            throw new RuntimeException('Release downloads must come from GitHub.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'GaugeIQ Updater',
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
        ]);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        if ($data === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('Unable to download the GaugeIQ release.' . ($error !== '' ? ' ' . $error : ''));
        }
        $effectiveHost = strtolower((string)parse_url($effectiveUrl, PHP_URL_HOST));
        if (!in_array($effectiveHost, [
            'github.com',
            'objects.githubusercontent.com',
            'release-assets.githubusercontent.com',
        ], true)) {
            throw new RuntimeException('The release download redirected to an untrusted host.');
        }

        if (file_put_contents($target, $data, LOCK_EX) === false) {
            throw new RuntimeException('Unable to save the GaugeIQ release.');
        }
    }

    private function parseChecksum(string $content): string
    {
        if (!preg_match('/\b([a-fA-F0-9]{64})\b/', $content, $m)) {
            throw new RuntimeException('The release checksum is invalid.');
        }
        return $m[1];
    }

    private function prepareWorkspace(): void
    {
        if (!is_dir($this->storage) && !mkdir($this->storage, 0750, true) && !is_dir($this->storage)) {
            throw new RuntimeException('Unable to create the update workspace.');
        }

        $lock = $this->storage . '/update.lock';
        if (is_file($lock) && (time() - (int)@filemtime($lock)) < 3600) {
            throw new RuntimeException('Another GaugeIQ update is already in progress.');
        }
        if (is_file($lock)) {
            @unlink($lock);
        }
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            throw new RuntimeException('Unable to lock the GaugeIQ update process.');
        }
        fwrite($handle, (string)getmypid());
        fclose($handle);
    }

    private function releaseLock(): void
    {
        @unlink($this->storage . '/update.lock');
    }



    private function pruneBackups(int $keep): void
    {
        try {
            $backups = glob($this->storage . '/backup-*', GLOB_ONLYDIR) ?: [];
            rsort($backups, SORT_STRING);
            foreach (array_slice($backups, max(0, $keep)) as $oldBackup) {
                $this->removeTree($oldBackup);
            }
        } catch (Throwable $e) {
            error_log('GaugeIQ could not prune old update backups: ' . $e->getMessage());
        }
    }

    private function backupDatabase(string $backup): ?array
    {
        $configPath = $this->root . '/config/local.php';
        if (!is_file($configPath)) {
            throw new RuntimeException('The existing GaugeIQ configuration is missing.');
        }
        $config = require $configPath;
        $database = is_array($config) ? ($config['database'] ?? []) : [];
        if (strtolower((string)($database['driver'] ?? 'sqlite')) !== 'sqlite') {
            // Automatic deployment is currently gated to SQLite. The manual
            // updater retains its existing MySQL support, but no MySQL dump is
            // claimed to exist when rollback is attempted.
            return null;
        }

        $databasePath = (string)($database['path'] ?? '');
        if ($databasePath === '' || !is_file($databasePath)) {
            throw new RuntimeException('The existing SQLite database could not be found for backup.');
        }

        $backupDirectory = $backup . '/.database';
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0750, true) && !is_dir($backupDirectory)) {
            throw new RuntimeException('Unable to create the database backup directory.');
        }
        $backupPath = $backupDirectory . '/gaugeiq.sqlite';
        $pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA wal_checkpoint(FULL)');
        $pdo->exec('VACUUM INTO ' . $pdo->quote($backupPath));
        $pdo = null;

        if (!is_file($backupPath) || filesize($backupPath) === 0) {
            throw new RuntimeException('The SQLite database backup could not be verified.');
        }

        return [
            'driver' => 'sqlite',
            'path' => $databasePath,
            'backup' => $backupPath,
        ];
    }

    private function restoreDatabase(array $databaseBackup): void
    {
        if (($databaseBackup['driver'] ?? '') !== 'sqlite') {
            return;
        }
        $source = (string)($databaseBackup['path'] ?? '');
        $backup = (string)($databaseBackup['backup'] ?? '');
        if ($source === '' || !is_file($backup)) {
            throw new RuntimeException('The SQLite rollback snapshot is missing.');
        }

        $temporary = $source . '.restore-' . bin2hex(random_bytes(6));
        if (!copy($backup, $temporary)) {
            throw new RuntimeException('Unable to prepare the SQLite rollback snapshot.');
        }

        // The updater runs without an open application DB connection here.
        // Clear SQLite sidecars before replacing the database with its snapshot.
        @unlink($source . '-wal');
        @unlink($source . '-shm');
        if (!rename($temporary, $source)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to restore the SQLite database snapshot.');
        }
        @chmod($source, 0640);
    }

    private function cliPhpBinary(): string
    {
        $runningBinary = (string)PHP_BINARY;
        $binaryName = strtolower(basename($runningBinary));
        if (is_executable($runningBinary) &&
            !str_contains($binaryName, 'fpm') &&
            !str_contains($binaryName, 'cgi')) {
            return $runningBinary;
        }

        $candidate = rtrim((string)PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php';
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }

        throw new RuntimeException('The PHP CLI executable could not be located for database migrations.');
    }

    private function runDatabaseMigration(): void
    {
        $script = $this->root . '/database/migrate.php';
        if (!is_file($script)) {
            throw new RuntimeException('The updated release is missing its database migration script.');
        }

        $pipes = [];
        $process = proc_open(
            [$this->cliPhpBinary(), $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root
        );

        if (!is_resource($process)) {
            throw new RuntimeException('GaugeIQ could not run the database migration.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            error_log('GaugeIQ migration failed: ' . trim((string)$stderr));
            throw new RuntimeException('The GaugeIQ database migration failed.');
        }
    }

    private function healthCheck(string $expectedVersion): void
    {
        $versionFile = $this->root . '/app/Version.php';
        $versionSource = is_file($versionFile) ? (string)file_get_contents($versionFile) : '';
        if (!preg_match('/VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/', $versionSource, $match) || $match[1] !== $expectedVersion) {
            throw new RuntimeException('The updated GaugeIQ version could not be verified.');
        }

        $autoload = $this->root . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('The updated GaugeIQ Web Push dependencies are missing.');
        }

        require_once $autoload;
        if (!class_exists('Minishlink\\WebPush\\WebPush')) {
            throw new RuntimeException('The updated GaugeIQ Web Push dependencies are invalid.');
        }

        $configPath = $this->root . '/config/local.php';
        if (!is_file($configPath)) {
            throw new RuntimeException('The existing GaugeIQ configuration was not preserved.');
        }

        require $this->root . '/app/Database.php';
        $config = require $configPath;
        $db = new Database($config);
        $pdo = $db->pdo();
        $pdo->query('SELECT 1');
        $pdo->query('SELECT version FROM gaugeiq_schema LIMIT 1')->fetchColumn();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $item = $path . '/' . $entry;
            is_dir($item) && !is_link($item) ? $this->removeTree($item) : @unlink($item);
        }
        @rmdir($path);
    }
}
