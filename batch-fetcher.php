<?php
require_once 'zlogger.php';

interface Fetcher {
	public function createHandle(int $page): CurlHandle|false;
	// Return decoded data array for this page
	public function processResponse(string $data): array;
	public function getLogLabel(): string;
}

abstract class BaseFetcher implements Fetcher
{
	# Hardcoding credentials is a very bad practice but it's okay here
	protected string $base_url = "https://cloud.balance.ge/sm/o/Balance/3973/en_US/hs/Exchange";
	protected string $auth_token = "Basic a3VwcmUyMEBnbWFpbC5jb206THVrYWt1cHJlNjUxMw==";

	protected int $pack_size;
	protected ZLogger $logger;

	public function __construct(int $pack_size, ZLogger $logger) {
		$this->pack_size = $pack_size;
		$this->logger = $logger;
	}

	abstract protected function getPath(): string;

	public function createHandle(int $page): CurlHandle|false {
		$query_params = "?Pack={$page}&PackSize={$this->pack_size}";
		$headers = ["Authorization: {$this->auth_token}"];
		$url = $this->base_url . $this->getPath() . $query_params;

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

		$this->logger->zlog("Created a request to $url");
		return $ch;
	}

	// Default: decode JSON array or return empty array
	public function processResponse(string $data): array {
		$this->logger->zlog("Processing data");
		$decoded = json_decode($data, true);
		if (empty($decoded) || !is_array($decoded)) {
			return [];
		}
		return $decoded;
	}
}

// ... existing code ...

class ItemsFetcher extends BaseFetcher
{
	protected function getPath(): string {
		return "/Items";
	}

	public function getLogLabel(): string {
		return "Products";
	}
}

// ... existing code ...

class ItemPricingFetcher extends BaseFetcher
{
	protected function getPath(): string {
		return "/ItemPricing";
	}

	public function getLogLabel(): string {
		return "ItemPricing";
	}

	// Keep raw pricing documents; normalization will interpret them
	public function processResponse(string $data): array {
		$this->logger->zlog("Processing data (ItemPricing)");
		$decoded = json_decode($data, true);
		if (empty($decoded) || !is_array($decoded)) {
			return [];
		}
		return $decoded;
	}
}

// ... existing code ...

class BatchRunner
{
	private int $batch_size;
	private Fetcher $fetcher;
	private ZLogger $logger;

	public function __construct(Fetcher $fetcher, int $batch_size, ZLogger $logger) {
		$this->fetcher = $fetcher;
		$this->batch_size = $batch_size;
		$this->logger = $logger;
	}

	/**
	 * Run batched requests until an empty page is encountered.
	 * Returns the concatenated array of records fetched across all pages.
	 */
	public function run(): array {
		$pack = 0;
		$got_last_page = false;
		$results_total = 0;
		$all_records = [];

		while (!$got_last_page) {
			$multi_handle = curl_multi_init();

			for ($i = 1; $i <= $this->batch_size; $i++) {
				$ch = $this->fetcher->createHandle($pack + $i);
				curl_multi_add_handle($multi_handle, $ch);
			}

			$running = null;
			$pack += $this->batch_size;
			$batch_number = $pack / $this->batch_size;
			$this->logger->zlog("Executing batch number $batch_number");

			do {
				$this->logger->zlog("Waiting for a response...");
				$status = curl_multi_exec($multi_handle, $running);
				if ($status !== CURLM_OK) {
					throw new \Exception(curl_multi_strerror(curl_multi_errno($multi_handle)));
				}

				curl_multi_select($multi_handle);

				while (($info = curl_multi_info_read($multi_handle)) !== false) {
					$this->logger->zlog("Processing a single request");
					if ($info['msg'] === CURLMSG_DONE) {
						$this->logger->zlog("Message is done!");
						$handle = $info['handle'];

						$info_result = $info['result'];
						$this->logger->zlog("Result: $info_result");

						if ($info['result'] === 60) {
							curl_multi_remove_handle($multi_handle, $handle);
							curl_close($handle);
							return $all_records;
						}

						if ($info['result'] === CURLE_OK) {
							$this->logger->zlog("Result CURLE_OK");
							$response = curl_multi_getcontent($handle);
							$status_code = curl_getinfo($handle, CURLINFO_HTTP_CODE);

							if ($status_code === 200) {
								$this->logger->zlog("Status 200");
								$records = $this->fetcher->processResponse($response);
								$count = count($records);
								$results_total += $count;
								$all_records = array_merge($all_records, $records);

								$label = $this->fetcher->getLogLabel();
								$this->logger->zlog("$label got from a single request: $count");
								$this->logger->zlog("Total $label: $results_total");

								if ($count === 0) {
									$got_last_page = true;
								}
							} else {
								$url = curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
								throw new \Exception("Failed the request with $status_code: $url");
							}
						}

						curl_multi_remove_handle($multi_handle, $handle);
						curl_close($handle);
					}
				}
			} while ($running > 0);

			curl_multi_close($multi_handle);
		}

		return $all_records;
	}
}
