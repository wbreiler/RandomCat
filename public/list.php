<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1); // Enable error display for debugging

// Prevent any output before headers
ob_start();

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../vendor/autoload.php';

// Load configuration
$config = [
    'region' => getenv('AWS_REGION') ?: 'us-east-1',
    'bucket' => getenv('S3_COMPRESSED_BUCKET') ?: 'meownowcompressed',
    'accessKey' => getenv('AWS_ACCESS_KEY_ID'),
    'secretKey' => getenv('AWS_SECRET_ACCESS_KEY'),
    'cacheFile' => __DIR__ . '/../logs/cat_list.json',
    'cacheExpiry' => 3600 // 1 hour
];

// Validate configuration
if (empty($config['accessKey']) || empty($config['secretKey'])) {
    error_log("AWS credentials are missing");
    echo json_encode([]);
    exit;
}

// Debug AWS configuration
error_log("AWS Configuration:");
error_log("Region: " . $config['region']);
error_log("Bucket: " . $config['bucket']);
error_log("Access Key length: " . (strlen($config['accessKey']) ?: 0));
error_log("Secret Key length: " . (strlen($config['secretKey']) ?: 0));

// Local file that stores the list of uploaded images (cache and fallback)
$catListFile = $config['cacheFile'];
$cacheExpiryTime = $config['cacheExpiry']; // Cache expiry time in seconds (1 hour)

// Create logs directory if it doesn't exist
$logsDir = dirname($catListFile);
if (!file_exists($logsDir)) {
    if (!mkdir($logsDir, 0750, true)) {
        error_log("Failed to create logs directory: " . $logsDir);
    } else {
        // Set ownership to www-data if running in Docker
        if (file_exists('/.dockerenv')) {
            chown($logsDir, 'www-data');
            chgrp($logsDir, 'www-data');
        }
    }
}

// Function to get cat images from S3 compressed bucket
function getCatsFromS3() {
    global $config;
    
    try {
        // Create an S3 client with credentials from environment variables
        $s3 = new Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => $config['region'],
            'credentials' => [
                'key'    => $config['accessKey'],
                'secret' => $config['secretKey'],
            ],
            'debug' => true // Enable debug mode
        ]);
        
        // Log bucket access attempt
        error_log("Attempting to list objects in bucket: " . $config['bucket']);
        
        // List all objects in the compressed bucket
        $result = $s3->listObjects([
            'Bucket' => $config['bucket']
        ]);
        
        $cats = [];
        
        // Process the results
        if (isset($result['Contents'])) {
            error_log("Found " . count($result['Contents']) . " objects in bucket");
            foreach ($result['Contents'] as $object) {
                error_log("Processing object: " . $object['Key']);
                // Only include image files
                if (preg_match('/\.(jpe?g|png)$/i', $object['Key'])) {
                    // Use a proxy URL that will fetch the image
                    $url = "get_image.php?key=" . urlencode($object['Key']);
                    
                    $cats[] = [
                        'key' => $object['Key'],
                        'url' => $url,
                        'filename' => basename($object['Key']),
                        'lastModified' => $object['LastModified']->format('Y-m-d H:i:s')
                    ];
                }
            }
            error_log("Found " . count($cats) . " image files");
        } else {
            error_log("No objects found in bucket");
        }
        
        return $cats;
    } catch (Exception $e) {
        // Log the error with more details
        error_log("S3 list error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        return [];
    }
}

// Function to get cat images from the local JSON file (cache/fallback)
function getCatsFromLocalFile() {
    global $catListFile;
    
    if (file_exists($catListFile)) {
        $catList = json_decode(file_get_contents($catListFile), true);
        return $catList ?: [];
    }
    
    return [];
}

// Function to save cat images to the local JSON file (cache)
function saveCatsToLocalFile($cats) {
    global $catListFile;
    
    // Add timestamp for cache management
    $data = [
        'timestamp' => time(),
        'cats' => $cats
    ];
    
    // Save to file
    file_put_contents($catListFile, json_encode($data, JSON_PRETTY_PRINT));
}

// Clear any output buffers
ob_clean();

try {
    // Check if we have a valid cache
    $useCache = false;
    if (file_exists($catListFile)) {
        $cacheData = json_decode(file_get_contents($catListFile), true);
        
        // Check if cache is valid (not expired)
        if (isset($cacheData['timestamp']) && 
            (time() - $cacheData['timestamp'] < $cacheExpiryTime)) {
            $useCache = true;
            $cats = $cacheData['cats'];
        }
    }

    // If cache is not valid, fetch from S3
    if (!$useCache) {
        $cats = getCatsFromS3();
        
        // If we got cats from S3, update the cache
        if (!empty($cats)) {
            saveCatsToLocalFile($cats);
        } else {
            // If S3 failed, try to use the cache even if expired
            $cacheData = getCatsFromLocalFile();
            if (isset($cacheData['cats'])) {
                $cats = $cacheData['cats'];
            }
        }
    }

    // If we have cats, return them as JSON
    if (!empty($cats)) {
        echo json_encode($cats);
        exit;
    } else {
        echo json_encode([]);
        exit;
    }
} catch (Exception $e) {
    // Log the error with more details
    error_log("Error in list.php: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    echo json_encode([]);
    exit;
}
?> 