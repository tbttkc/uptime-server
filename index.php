<?php
declare(strict_types=1);

// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null, // null or '' or 'jYtI:PHX-AD-1' or ['jYtI:PHX-AD-1','jYtI:PHX-AD-2']
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

// ==========================================
// ĐOẠN CODE THÊM VÀO ĐỂ IN BIẾN MÔI TRƯỜNG
// ==========================================
echo "\n--- KỂM TRA BIẾN MÔI TRƯỜNG TỪ RAILWAY ---\n";
echo "REGION: " . getenv('OCI_REGION') . "\n";
echo "TENANCY_ID: " . getenv('OCI_TENANCY_ID') . "\n";
echo "USER_ID: " . getenv('OCI_USER_ID') . "\n";
echo "FINGERPRINT: " . getenv('OCI_KEY_FINGERPRINT') . "\n";
echo "AVAILABILITY_DOMAIN: " . (getenv('OCI_AVAILABILITY_DOMAIN') ?: 'CHƯA SET') . "\n";
echo "SUBNET_ID: " . getenv('OCI_SUBNET_ID') . "\n";
echo "IMAGE_ID: " . getenv('OCI_IMAGE_ID') . "\n";
echo "SHAPE: " . getenv('OCI_SHAPE') . "\n";
echo "CPU/RAM: " . getenv('OCI_OCPUS') . " Core / " . getenv('OCI_MEMORY_IN_GBS') . " GB\n";

$keyPath = getenv('OCI_PRIVATE_KEY_FILENAME');
if (file_exists((string)$keyPath)) {
    $keyContent = file_get_contents((string)$keyPath);
    $pkey = openssl_pkey_get_private($keyContent);
    if ($pkey !== false) {
        echo "TRẠNG THÁI KEY: [OK] File key HOÀN HẢO, định dạng RSA chuẩn!\n";
    } else {
        echo "TRẠNG THÁI KEY: [LỖI NẶNG] File key đã bị hỏng định dạng (Corrupted)!\n";
        echo "Nội dung bị lỗi bắt đầu bằng: " . substr(trim($keyContent), 0, 40) . "...\n";
    }
} else {
    echo "TRẠNG THÁI KEY: [LỖI] Không tìm thấy file key!\n";
}
echo "------------------------------------------\n\n";
// ==========================================

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}
$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    return new \Hitrov\Notification\Telegram();
})();

$shape = getenv('OCI_SHAPE');

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

$instances = $api->getInstances($config);

$existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
if ($existingInstances) {
    echo "$existingInstances\n";
    return;
}

if (!empty($config->availabilityDomains)) {
    if (is_array($config->availabilityDomains)) {
        $availabilityDomains = $config->availabilityDomains;
    } else {
        $availabilityDomains = [ $config->availabilityDomains ];
    }
} else {
    $availabilityDomains = $api->getAvailabilityDomains($config);
}

foreach ($availabilityDomains as $availabilityDomainEntity) {
    $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
    try {
        $instanceDetails = $api->createInstance($config, $shape, getenv('OCI_SSH_PUBLIC_KEY'), $availabilityDomain);
    } catch(ApiCallException $e) {
        $message = $e->getMessage();
        echo "$message\n";

        if (
            $e->getCode() === 500 &&
            strpos($message, 'InternalError') !== false &&
            strpos($message, 'Out of host capacity') !== false
        ) {
            // trying next availability domain
            sleep(16);
            continue;
        }

        // current config is broken
        return;
    }

    // success
    $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
    echo "$message\n";
    if ($notifier->isSupported()) {
        $notifier->notify($message);
    }

    return;
}
