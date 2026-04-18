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

// ==========================================
// ĐÃ THÊM TRIM() ĐỂ DIỆT GỌN MỌI KÝ TỰ ẨN/KHOẢNG TRẮNG
// ==========================================
$config = new OciConfig(
    trim(getenv('OCI_REGION')),
    trim(getenv('OCI_USER_ID')),
    trim(getenv('OCI_TENANCY_ID')),
    trim(getenv('OCI_KEY_FINGERPRINT')),
    trim(getenv('OCI_PRIVATE_KEY_FILENAME')),
    getenv('OCI_AVAILABILITY_DOMAIN') ? trim(getenv('OCI_AVAILABILITY_DOMAIN')) : null,
    trim(getenv('OCI_SUBNET_ID')),
    trim(getenv('OCI_IMAGE_ID')),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$bootVolumeSizeInGBs = (string) getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS');
$bootVolumeId = (string) getenv('OCI_BOOT_VOLUME_ID');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs(trim($bootVolumeSizeInGBs));
} elseif ($bootVolumeId) {
    $config->setBootVolumeId(trim($bootVolumeId));
}

echo "\n--- KIỂM TRA TỪ RAILWAY (PHIÊN BẢN CHỐNG DẤU CÁCH ẨN) ---\n";
echo "TENANCY_ID: [" . $config->tenancyId . "]\n";
echo "USER_ID: [" . $config->userId . "]\n";
echo "FINGERPRINT: [" . $config->keyFingerprint . "]\n";

$keyPath = $config->privateKeyFilename;
if (file_exists((string)$keyPath)) {
    $keyContent = file_get_contents((string)$keyPath);
    $pkey = openssl_pkey_get_private($keyContent);
    if ($pkey !== false) {
        echo "TRẠNG THÁI KEY: [OK] File key HOÀN HẢO, định dạng RSA chuẩn!\n";
    } else {
        echo "TRẠNG THÁI KEY: [LỖI NẶNG] File key đã bị hỏng định dạng (Corrupted)!\n";
    }
} else {
    echo "TRẠNG THÁI KEY: [LỖI] Không tìm thấy file key!\n";
}
echo "------------------------------------------\n\n";

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

$shape = trim(getenv('OCI_SHAPE'));

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
        $instanceDetails = $api->createInstance($config, $shape, trim(getenv('OCI_SSH_PUBLIC_KEY')), $availabilityDomain);
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
