<?php

namespace Drupal\ntrip_config_editor;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Service for parsing NTRIP caster logs.
 */
class NtripLogParser {
  use StringTranslationTrait;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new NtripLogParser object.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * The path to the configuration file.
   */
  const CONFIG_FILE_PATH = '/var/log/ntripcaster/';

  /**
   * Parse และสรุป log
   */
  public function formatLogContent($log_content) {
    if (empty($log_content)) {
      return null;
    }

    $lines = explode("\n", trim($log_content));
    $access_data = [];
    $server_events = [];
    $statistics = [
      'total_connections' => 0,
      'total_bytes' => 0,
      'total_duration' => 0,
      'unique_users' => [],
      'unique_ips' => []
    ];
    $user_summary = [];

    foreach ($lines as $line) {
      $line = trim($line);
      if (empty($line)) continue;

      if ($this->isAccessLogLine($line)) {
        $parsed = $this->parseAccessLogLine($line);
        if ($parsed) {
          $access_data[] = $parsed;

          // Note: $parsed[7] from parseAccessLogLine is already formatted bytes string
          // We need the raw bytes. Let's adjust parseAccessLogLine
          // ... or rather, let's adjust this function to re-parse what parseAccessLogLine did
          
          // Re-parsing the original line for raw data
          $parts = str_getcsv($line);
          $raw_bytes = isset($parts[7]) ? intval($parts[7]) : 0;
          $raw_duration = isset($parts[6]) ? intval($parts[6]) : 0;
          $user = $parsed[2]; // User from parsed data
          $station = $parsed[4]; // Station from parsed data

          $statistics['total_connections']++;
          $statistics['total_bytes'] += $raw_bytes;
          $statistics['total_duration'] += $raw_duration;
          $statistics['unique_users'][$user] = true;
          $statistics['unique_ips'][$parsed[3]] = true;

          // update per-user summary
          if (!isset($user_summary[$user])) {
            $user_summary[$user] = [];
          }

          if (!isset($user_summary[$user][$station])) {
            $user_summary[$user][$station] = ['duration' => 0, 'bytes' => 0];
          }

          $user_summary[$user][$station]['duration'] += $raw_duration;
          $user_summary[$user][$station]['bytes'] += $raw_bytes;
        }
      }
      else {
        $server_events[] = $line;
      }
    }

    $statistics['avg_duration'] = $statistics['total_connections'] > 0
      ? $statistics['total_duration'] / $statistics['total_connections']
      : 0;

    return [
      'access_data' => $access_data,
      'server_events' => $server_events,
      'statistics' => $statistics,
      'user_summary' => $user_summary,
    ];
  }

  public function isAccessLogLine($line) {
    return preg_match('/^\d{2}\/\w{3}\/\d{4},\d{2}:\d{2}:\d{2},.+/', $line);
  }

  public function parseAccessLogLine($line) {
    $parts = str_getcsv($line);
    if (count($parts) >= 8) {
      return [
        $parts[0], // Date
        $parts[1], // Time
        $parts[2], // User
        $parts[3], // IP
        $parts[4], // Station
        $parts[5], // Client
        $parts[6], // Seconds
        $this->formatBytes(intval($parts[7])) // Formatted bytes
      ];
    }
    return null;
  }

  public function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
      $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
  }

  public function parseBytesToInt($str) {
    $units = ['B'=>1, 'KB'=>1024, 'MB'=>1048576, 'GB'=>1073741824];
    if (preg_match('/([\d\.]+)\s*(B|KB|MB|GB)/i', $str, $m)) {
      return (float)$m[1] * $units[strtoupper($m[2])];
    }
    // This part was flawed in original code, formatLogContent needs raw bytes
    // But we will keep it as it was for compatibility with original logic if needed
    return (int)$str;
  }

  public function getUserOptions() {
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple();
    $user_options = ['all' => $this->t('All Users')];  
    foreach ($users as $user) {
      $user_options[$user->id()] = $user->getAccountName();
    }
    return $user_options;
  }

  /**
   * ดึงรายชื่อ User จากไฟล์ log ที่มีอยู่จริง
   */
  public function getUserOptionsFromLogs($form_state) {
    $user_options = ['all' => $this->t('All Users')];
    
    // ถ้ามี formatted_log แล้ว ใช้ข้อมูลจากนั้น
    $formatted_log = $form_state->get('formatted_log');
    if ($formatted_log && isset($formatted_log['user_summary'])) {
      foreach ($formatted_log['user_summary'] as $user => $stations) {
        $user_options[$user] = $user;
      }
      ksort($user_options); // เรียงตามตัวอักษร
      return $user_options;
    }

    // ถ้ายังไม่มีข้อมูล ให้อ่านจากไฟล์ log ทั้งหมดที่มี
    $log_directory = self::CONFIG_FILE_PATH; // <-- ใช้ const
    if (!is_dir($log_directory)) {
      return $user_options;
    }

    $users_found = [];
    $log_files = glob($log_directory . 'access-*.log');
    
    foreach ($log_files as $log_file) {
      if (file_exists($log_file) && is_readable($log_file)) {
        $content = file_get_contents($log_file);
        $lines = explode("\n", trim($content));
        
        foreach ($lines as $line) {
          $line = trim($line);
          if (empty($line)) continue;
          
          if ($this->isAccessLogLine($line)) {
            $parts = str_getcsv($line);
            if (isset($parts[2]) && !empty($parts[2])) {
              $users_found[$parts[2]] = $parts[2];
            }
          }
        }
      }
    }

    if (!empty($users_found)) {
      ksort($users_found);
      $user_options = array_merge($user_options, $users_found);
    }

    return $user_options;
  }

  public function getLogFilesByDate($start_timestamp, $end_timestamp) {
    $log_files = [];
    $log_directory = self::CONFIG_FILE_PATH; // <-- ใช้ const

    if (is_dir($log_directory)) {
      for ($current_date = $start_timestamp; $current_date <= $end_timestamp; $current_date = strtotime("+1 day", $current_date)) {
        $filename = $log_directory . "access-" . date('ymd', $current_date) . ".log";
        if (file_exists($filename)) {
          $log_files[] = $filename;
        }
      }
    }
    return array_unique($log_files);
  }

  public function filterLogByUser($log_content, $user_id) {
    $filtered_log = '';
    $lines = explode("\n", $log_content);
    foreach ($lines as $line) {
      if (strpos($line, $user_id) !== false) {
        $filtered_log .= $line . "\n";
      }
    }
    return $filtered_log;
  }
}