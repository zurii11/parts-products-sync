<?php

class ZLogger
{
	private string $logfile_path;
	private bool $stream_logs;

	public function __construct(string $logfile_path = "sync.log", bool $stream_logs = false) {
		$this->logfile_path = $logfile_path;
		$this->stream_logs = $stream_logs;
	}

	public function zlog(string $str) {
		$date = date('H:i:s');
		$log_string = "[$date] $str\n";
		file_put_contents($this->logfile_path, $log_string, FILE_APPEND);
		fwrite(STDOUT, $log_string);
	}
}
