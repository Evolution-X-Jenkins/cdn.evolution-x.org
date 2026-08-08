<?php
/**
 * Bunny Storage SDK utilities.
 */

require_once __DIR__ . '/config.php';

use Bunny\Storage\Client as BunnyStorageClient;
use Bunny\Storage\Region as BunnyRegion;

function bunny_normalize_region($region) {
	$normalized = strtolower(trim((string)$region));
	if ($normalized === '') {
		return BunnyRegion::FALKENSTEIN;
	}

	if (isset(BunnyRegion::LIST[$normalized])) {
		return $normalized;
	}

	return BunnyRegion::FALKENSTEIN;
}

function bunny_get_storage_client() {
	static $client = null;

	if ($client !== null) {
		return $client;
	}

	$zone = trim((string)BUNNY_STORAGE_ZONE);
	$accessKey = trim((string)BUNNY_STORAGE_ACCESS_KEY);
	if ($zone === '' || $zone === 'your-storage-zone' || $accessKey === '' || $accessKey === 'your-bunny-storage-access-key') {
		throw new RuntimeException('Bunny storage configuration is missing. Set BUNNY_STORAGE_ZONE and BUNNY_STORAGE_ACCESS_KEY.');
	}

	$client = new BunnyStorageClient(
		$accessKey,
		$zone,
		bunny_normalize_region(BUNNY_STORAGE_REGION)
	);

	return $client;
}

function bunny_describe_file_raw($path) {
	$zone = trim((string)BUNNY_STORAGE_ZONE);
	$accessKey = trim((string)BUNNY_STORAGE_ACCESS_KEY);
	$region = bunny_normalize_region(BUNNY_STORAGE_REGION);

	if ($zone === '' || $accessKey === '') {
		throw new RuntimeException('Bunny storage configuration is missing.');
	}

	$baseUrl = BunnyRegion::getBaseUrl($region);
	$normalizedPath = ltrim(bunny_normalize_relative_path($path), '/');
	$url = rtrim($baseUrl, '/') . '/' . $zone . '/' . $normalizedPath;

	$context = stream_context_create([
		'http' => [
			'method' => 'DESCRIBE',
			'header' => "AccessKey: {$accessKey}\r\n",
			'timeout' => 25,
		],
	]);

	$raw = @file_get_contents($url, false, $context);
	if ($raw === false) {
		throw new RuntimeException('Failed to fetch Bunny file metadata.');
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		throw new RuntimeException('Invalid Bunny file metadata response.');
	}

	return [
		'length' => isset($decoded['Length']) ? (int)$decoded['Length'] : 0,
		'checksum' => strtolower((string)($decoded['Checksum'] ?? '')),
		'is_directory' => !empty($decoded['IsDirectory']),
		'path' => (string)($decoded['ObjectName'] ?? '') . '/' . (string)($decoded['Path'] ?? ''),
	];
}

function bunny_normalize_relative_path($path) {
	$trimmed = trim((string)$path);
	if ($trimmed === '' || $trimmed === '/') {
		return '/';
	}

	$normalized = '/' . trim($trimmed, '/');
	return preg_replace('#/+#', '/', $normalized);
}

function bunny_relative_to_bucket_prefix($relativePath) {
	$normalized = bunny_normalize_relative_path($relativePath);
	if ($normalized === '/') {
		return '';
	}

	return trim($normalized, '/') . '/';
}

function bunny_list_directory_items($relativePath) {
	$result = bunny_list_directory_items_with_source($relativePath);
	return $result['items'];
}

function bunny_list_directory_items_with_source($relativePath) {
	$relativePath = bunny_normalize_relative_path($relativePath);
	$prefix = bunny_relative_to_bucket_prefix($relativePath);

	try {
		$client = bunny_get_storage_client();
		$files = $client->listFiles($prefix);

		$items = [];
		foreach ($files as $file) {
			$name = (string)$file->getName();
			if (bunny_should_hide_name($name)) {
				continue;
			}

			$isDir = (bool)$file->isDirectory();
			$itemPath = $relativePath === '/' ? '/' . $name : $relativePath . '/' . $name;
			$itemPath = bunny_normalize_relative_path($itemPath);

			$items[] = [
				'name' => $name,
				'path' => $itemPath,
				'is_dir' => $isDir,
				'size' => $isDir ? 0 : (int)$file->getSize(),
				'modified_at' => $file->getDateModified()->getTimestamp(),
				'icon' => get_file_icon($name, $isDir),
			];
		}

		bunny_sort_items($items);
		return [
			'items' => $items,
			'source' => 'bunny',
		];
	} catch (Throwable $e) {
		error_log('Bunny SDK listing fallback activated for ' . $relativePath . ': ' . $e->getMessage());
	}

	$items = bunny_list_directory_items_raw($prefix, $relativePath);
	bunny_sort_items($items);
	return [
		'items' => $items,
		'source' => 'fallback',
	];
}

function bunny_should_hide_name($name) {
	$name = (string)$name;
	if ($name === '' || $name === '.' || $name === '..') {
		return true;
	}

	if (defined('LISTING_HIDDEN_NAMES') && is_array(LISTING_HIDDEN_NAMES)) {
		return in_array($name, LISTING_HIDDEN_NAMES, true);
	}

	return false;
}

function bunny_sort_items(array &$items) {
	usort($items, function($a, $b) {
		if ((bool)$a['is_dir'] !== (bool)$b['is_dir']) {
			return ((int)$b['is_dir']) - ((int)$a['is_dir']);
		}

		return strcasecmp((string)$a['name'], (string)$b['name']);
	});
}

function bunny_list_directory_items_raw($prefix, $relativePath) {
	$zone = trim((string)BUNNY_STORAGE_ZONE);
	$accessKey = trim((string)BUNNY_STORAGE_ACCESS_KEY);
	$region = bunny_normalize_region(BUNNY_STORAGE_REGION);

	$baseUrl = BunnyRegion::getBaseUrl($region);
	$path = $zone . '/' . ltrim((string)$prefix, '/');
	$url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

	$context = stream_context_create([
		'http' => [
			'method' => 'GET',
			'header' => "AccessKey: {$accessKey}\r\n",
			'timeout' => 25,
		],
	]);

	$raw = @file_get_contents($url, false, $context);
	if ($raw === false) {
		throw new RuntimeException('Failed to fetch Bunny storage listing from API');
	}

	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		throw new RuntimeException('Invalid Bunny storage listing response');
	}

	$items = [];
	foreach ($decoded as $entry) {
		if (!is_array($entry)) {
			continue;
		}

		$name = (string)($entry['ObjectName'] ?? '');
		if (bunny_should_hide_name($name)) {
			continue;
		}

		$isDir = !empty($entry['IsDirectory']);
		$itemPath = $relativePath === '/' ? '/' . $name : $relativePath . '/' . $name;
		$itemPath = bunny_normalize_relative_path($itemPath);

		$modifiedRaw = (string)($entry['LastChanged'] ?? $entry['DateCreated'] ?? '');
		$modifiedAt = strtotime($modifiedRaw);
		if ($modifiedAt === false) {
			$modifiedAt = 0;
		}

		$items[] = [
			'name' => $name,
			'path' => $itemPath,
			'is_dir' => $isDir,
			'size' => $isDir ? 0 : (int)($entry['Length'] ?? 0),
			'modified_at' => (int)$modifiedAt,
			'icon' => get_file_icon($name, $isDir),
		];
	}

	return $items;
}
