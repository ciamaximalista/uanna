<?php

namespace Oannes;

final class AndroidAppBuilder
{
    public function __construct(
        private readonly FileStore $store,
        private readonly array $config,
    ) {
    }

    public function status(): array
    {
        $root = $this->rootDir();
        $androidDir = $root . '/android';
        $gradle = $this->gradlePath($androidDir);
        $javaHome = $this->javaHome($androidDir);
        $sdk = $this->sdkPath($androidDir);
        $missing = [];

        if (!function_exists('exec')) {
            $missing[] = 'PHP debe permitir exec() para lanzar Gradle desde el panel.';
        }

        if (!is_dir($androidDir)) {
            $missing[] = 'Directorio android/ con el proyecto de la app.';
        }

        if ($gradle === '') {
            $missing[] = 'Gradle. Instala Gradle o incluye android/.toolchain/gradle/current/bin/gradle.';
        }

        if ($javaHome === '') {
            $missing[] = 'JDK 17. En Debian/Ubuntu: sudo apt install openjdk-17-jdk';
        }

        if ($sdk === '') {
            $missing[] = 'Android SDK con platform android-35 y build-tools. Instala Android Studio o Android command line tools.';
        }

        return [
            'ready' => $missing === [],
            'missing' => $missing,
            'manifest' => $this->manifest(),
        ];
    }

    public function build(string $appName, string $faviconPath): array
    {
        $status = $this->status();
        if (!$status['ready']) {
            throw new \RuntimeException('Faltan dependencias para compilar la app.');
        }

        $root = $this->rootDir();
        $androidDir = $root . '/android';
        $publicDir = rtrim((string)($this->config['public_dir'] ?? $root . '/oannes/public'), '/');
        $assetsDir = $publicDir . '/assets/instance';
        $apkSource = $this->androidBuildDir() . '/outputs/apk/debug/app-debug.apk';
        $apkTarget = $assetsDir . '/uanna-app.apk';
        $apkUrl = '/assets/instance/uanna-app.apk';

        $this->prepareAndroidProject($androidDir, trim($appName) !== '' ? $appName : 'Uanna', $faviconPath);
        $this->runGradle($androidDir);

        if (!is_file($apkSource)) {
            throw new \RuntimeException('Gradle terminó, pero no se encontró el APK esperado.');
        }

        if (!is_dir($assetsDir) && !mkdir($assetsDir, 0775, true) && !is_dir($assetsDir)) {
            throw new \RuntimeException('No se pudo crear el directorio público de la app.');
        }

        @chmod($assetsDir, 02775);

        if (!copy($apkSource, $apkTarget)) {
            throw new \RuntimeException('No se pudo publicar el APK compilado.');
        }

        @chmod($apkTarget, 0664);

        $manifest = [
            'app_name' => trim($appName) !== '' ? $appName : 'Uanna',
            'url' => $apkUrl,
            'path' => $apkTarget,
            'bytes' => filesize($apkTarget) ?: 0,
            'built_at' => gmdate('c'),
        ];
        $this->store->writeJson($this->manifestPath(), $manifest);

        return $manifest;
    }

    public function manifest(): ?array
    {
        $path = $this->manifestPath();
        if (!is_file($path)) {
            return null;
        }

        try {
            $manifest = $this->store->readJson($path);
        } catch (\Throwable) {
            return null;
        }

        $apkPath = is_string($manifest['path'] ?? null) ? $manifest['path'] : '';
        $url = is_string($manifest['url'] ?? null) ? $manifest['url'] : '';

        return $apkPath !== '' && $url !== '' && is_file($apkPath) ? $manifest : null;
    }

    private function prepareAndroidProject(string $androidDir, string $appName, string $faviconPath): void
    {
        $strings = $androidDir . '/app/src/main/res/values/strings.xml';
        $siteUrl = rtrim((string)$this->config['base_url'], '/');
        $content = '<resources>' . "\n"
            . '    <string name="app_name">' . htmlspecialchars($appName, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</string>' . "\n"
            . '    <string name="site_url">' . htmlspecialchars($siteUrl, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</string>' . "\n"
            . '    <string name="notification_channel">' . htmlspecialchars($appName, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</string>' . "\n"
            . '</resources>' . "\n";

        if (file_put_contents($strings, $content, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo actualizar el nombre de la app Android.');
        }

        @chmod($strings, 0664);
        $this->writeLocalProperties($androidDir);
        $this->writeLauncherIcons($androidDir, $faviconPath);
    }

    private function writeLocalProperties(string $androidDir): void
    {
        $sdk = $this->sdkPath($androidDir);
        if ($sdk === '') {
            return;
        }

        $path = $androidDir . '/local.properties';
        $content = 'sdk.dir=' . str_replace('\\', '/', $sdk) . "\n";
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException('No se pudo escribir android/local.properties.');
        }

        @chmod($path, 0664);
    }

    private function writeLauncherIcons(string $androidDir, string $faviconPath): void
    {
        if (!function_exists('imagecreatefrompng')) {
            return;
        }

        $sourcePath = $this->publicAssetPath($faviconPath);
        if ($sourcePath === '' || !is_file($sourcePath)) {
            return;
        }

        $bytes = file_get_contents($sourcePath);
        $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        if (!$source instanceof \GdImage) {
            return;
        }

        $sizes = [
            'mipmap-mdpi' => 48,
            'mipmap-hdpi' => 72,
            'mipmap-xhdpi' => 96,
            'mipmap-xxhdpi' => 144,
            'mipmap-xxxhdpi' => 192,
        ];

        foreach ($sizes as $dir => $size) {
            $targetDir = $androidDir . '/app/src/main/res/' . $dir;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                continue;
            }

            @chmod($targetDir, 02775);
            $icon = $this->resizeIcon($source, $size);
            imagepng($icon, $targetDir . '/ic_launcher.png');
            imagepng($icon, $targetDir . '/ic_launcher_round.png');
            @chmod($targetDir . '/ic_launcher.png', 0664);
            @chmod($targetDir . '/ic_launcher_round.png', 0664);
            imagedestroy($icon);
        }

        $drawableDir = $androidDir . '/app/src/main/res/drawable';
        if (is_dir($drawableDir)) {
            $foreground = $this->resizeIcon($source, 432);
            imagepng($foreground, $drawableDir . '/app_icon_foreground.png');
            @chmod($drawableDir . '/app_icon_foreground.png', 0664);
            imagedestroy($foreground);
        }

        imagedestroy($source);
    }

    private function resizeIcon(\GdImage $source, int $size): \GdImage
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $target = (int)round($size * 0.82);
        $scale = min($target / max(1, $srcW), $target / max(1, $srcH));
        $dstW = max(1, (int)round($srcW * $scale));
        $dstH = max(1, (int)round($srcH * $scale));
        $dstX = (int)floor(($size - $dstW) / 2);
        $dstY = (int)floor(($size - $dstH) / 2);

        imagecopyresampled($canvas, $source, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);
        return $canvas;
    }

    private function runGradle(string $androidDir): void
    {
        $gradle = $this->gradlePath($androidDir);
        $javaHome = $this->javaHome($androidDir);
        $sdk = $this->sdkPath($androidDir);
        $appStateDir = $this->store->dataDir() . '/app';
        $userSuffix = $this->processUserSuffix();
        $gradleHome = $appStateDir . '/gradle-home-' . $userSuffix;
        $tmpDir = $appStateDir . '/tmp-' . $userSuffix;
        $androidUserHome = $appStateDir . '/android-home-' . $userSuffix;
        $androidBuildDir = $this->androidBuildDir();
        $signingDir = $appStateDir . '/signing';
        $debugKeystore = $signingDir . '/debug.keystore';

        foreach ([$appStateDir, $gradleHome, $tmpDir, $androidUserHome, $androidBuildDir, $signingDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('No se pudo preparar el entorno de compilación Android.');
            }
            @chmod($dir, 02775);
        }

        $this->ensureDebugKeystore($androidDir, $debugKeystore);

        $command = 'cd ' . escapeshellarg($androidDir)
            . ' && HOME=' . escapeshellarg($appStateDir)
            . ' TMPDIR=' . escapeshellarg($tmpDir)
            . ' GRADLE_USER_HOME=' . escapeshellarg($gradleHome)
            . ' JAVA_HOME=' . escapeshellarg($javaHome)
            . ' JAVA_TOOL_OPTIONS=' . escapeshellarg('-Duser.home=' . $androidUserHome)
            . ' GRADLE_OPTS=' . escapeshellarg('-Duser.home=' . $androidUserHome)
            . ' UANNA_ANDROID_KEYSTORE=' . escapeshellarg($debugKeystore)
            . ' UANNA_ANDROID_BUILD_DIR=' . escapeshellarg($androidBuildDir)
            . ' ANDROID_HOME=' . escapeshellarg($sdk)
            . ' ANDROID_SDK_ROOT=' . escapeshellarg($sdk)
            . ' ANDROID_USER_HOME=' . escapeshellarg($androidUserHome)
            . ' PATH=' . escapeshellarg($javaHome . '/bin:' . $sdk . '/platform-tools:' . $sdk . '/cmdline-tools/latest/bin:/usr/local/bin:/usr/bin:/bin')
            . ' ' . escapeshellarg($gradle)
            . ' --no-daemon --stacktrace assembleDebug 2>&1';

        $output = [];
        $code = 0;
        exec($command, $output, $code);

        if ($code !== 0) {
            $tail = implode("\n", array_slice($output, -80));
            throw new \RuntimeException("No se pudo compilar la app:\n" . $tail);
        }
    }

    private function publicAssetPath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return '';
        }

        $publicDir = rtrim((string)($this->config['public_dir'] ?? $this->rootDir() . '/oannes/public'), '/');
        return $publicDir . '/' . ltrim($path, '/');
    }

    private function gradlePath(string $androidDir): string
    {
        $local = $androidDir . '/.toolchain/gradle/current/bin/gradle';
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        if (!function_exists('shell_exec')) {
            return '';
        }

        $global = trim((string)shell_exec('command -v gradle 2>/dev/null'));
        return $global !== '' ? $global : '';
    }

    private function javaHome(string $androidDir): string
    {
        $local = $androidDir . '/.toolchain/jdk';
        if (is_file($local . '/bin/java')) {
            return $local;
        }

        if (!function_exists('shell_exec')) {
            return '';
        }

        $java = trim((string)shell_exec('command -v java 2>/dev/null'));
        if ($java === '') {
            return '';
        }

        $home = trim((string)shell_exec('dirname "$(dirname "$(readlink -f ' . escapeshellarg($java) . ')")" 2>/dev/null'));
        return $home !== '' ? $home : '';
    }

    private function sdkPath(string $androidDir): string
    {
        $local = $androidDir . '/.toolchain/sdk';
        if (is_dir($local . '/platforms/android-35') && is_dir($local . '/build-tools')) {
            return $local;
        }

        foreach (['ANDROID_HOME', 'ANDROID_SDK_ROOT'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '' && is_dir($value . '/platforms/android-35') && is_dir($value . '/build-tools')) {
                return $value;
            }
        }

        return '';
    }

    private function processUserSuffix(): string
    {
        if (function_exists('posix_geteuid')) {
            return (string)posix_geteuid();
        }

        return 'web';
    }

    private function androidBuildDir(): string
    {
        return $this->store->dataDir() . '/app/android-build-' . $this->processUserSuffix();
    }

    private function ensureDebugKeystore(string $androidDir, string $debugKeystore): void
    {
        if (is_file($debugKeystore)) {
            @chmod($debugKeystore, 0664);
            return;
        }

        $legacy = getenv('HOME');
        if (is_string($legacy) && $legacy !== '') {
            $legacy = rtrim($legacy, '/') . '/.android/debug.keystore';
            if (is_file($legacy) && copy($legacy, $debugKeystore)) {
                @chmod($debugKeystore, 0664);
                return;
            }
        }

        $javaHome = $this->javaHome($androidDir);
        $keytool = $javaHome !== '' ? $javaHome . '/bin/keytool' : '';
        if ($keytool === '' || !is_file($keytool)) {
            throw new \RuntimeException('No se pudo preparar la firma Android: falta keytool.');
        }

        $command = escapeshellarg($keytool)
            . ' -genkeypair -v'
            . ' -keystore ' . escapeshellarg($debugKeystore)
            . ' -storepass android'
            . ' -alias androiddebugkey'
            . ' -keypass android'
            . ' -keyalg RSA'
            . ' -keysize 2048'
            . ' -validity 10000'
            . ' -dname ' . escapeshellarg('CN=Android Debug,O=Android,C=US')
            . ' 2>&1';

        $output = [];
        $code = 0;
        exec($command, $output, $code);

        if ($code !== 0 || !is_file($debugKeystore)) {
            $tail = implode("\n", array_slice($output, -20));
            throw new \RuntimeException("No se pudo preparar la firma Android:\n" . $tail);
        }

        @chmod($debugKeystore, 0664);
    }

    private function manifestPath(): string
    {
        return $this->store->dataDir() . '/app/build.json';
    }

    private function rootDir(): string
    {
        return rtrim((string)($this->config['root_dir'] ?? dirname(__DIR__, 3)), '/');
    }
}
