<?php

namespace Drupal\ntrip_config_editor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Drupal\ntrip_config_editor\NtripLogParser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Controller for displaying a user's NTRIP bill.
 */
class NtripBillController extends ControllerBase {

  /**
   * The log parser service.
   *
   * @var \Drupal\ntrip_config_editor\NtripLogParser
   */
  protected $logParser;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new NtripBillController object.
   */
  public function __construct(NtripLogParser $log_parser, AccountInterface $current_user) {
    $this->logParser = $log_parser;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ntrip_config_editor.log_parser'),
      $container->get('current_user')
    );
  }

  /**
   * Displays the bill for a user.
   */
  public function showBill(UserInterface $user) {
    // ตรวจสอบสิทธิ์ว่า user ที่ login อยู่ดูบิลของตัวเอง (หรือเป็น admin)
    if ($this->currentUser->id() != $user->id() && !$this->currentUser->hasPermission('administer users')) {
      throw new AccessDeniedHttpException();
    }

    // --- 1. ดึง NTRIP Username จากโปรไฟล์ ---
    // *** เปลี่ยน 'field_ntrip_username' ให้ตรงกับ machine name ของฟิลด์ที่สร้าง ***
    $ntrip_username = $user->get('field_ntrip_username')->value; // <--- !! แก้ไขตรงนี้

    if (empty($ntrip_username)) {
      return [
        '#markup' => $this->t('NTRIP username is not set for this account.'),
      ];
    }

    // --- 2. กำหนดช่วงเวลา (เป็น "เดือนปัจจุบัน") ---
    // นี่คือยอดที่ "ณ ตอนนี้"
    $start_date_str = date('Y-m-01'); // วันที่ 1 ของเดือนปัจจุบัน
    $end_date_str = date('Y-m-d');    // วันที่ปัจจุบัน (วันนี้)

    $start_timestamp = strtotime($start_date_str . ' 00:00:00');
    $end_timestamp = strtotime($end_date_str . ' 23:59:59'); //รวมเวลาทั้งหมดของวันนี้

    // --- 3. ดึง Rate ที่ Admin บันทึกไว้ ---
    $config = $this->config('ntrip_config_editor.settings');
    $time_rate = (float)($config->get('time_rate') ?? 0.2);
    $data_rate = (float)($config->get('data_rate') ?? 1.0);

    // (เลือก Cost Type ที่จะใช้คำนวณ) // ดึงค่าว่า Admin เลือกคำนวณแบบไหน (time หรือ data) จากหน้า Settings
    $cost_type = $config->get('cost_calculation_method') ?? 'time';
    $rate_display = ($cost_type === 'time') ? $time_rate : $data_rate;
    $unit = ($cost_type === 'time') ? 'min' : 'MB';

    // --- 4. โหลดและ Parse Log (ใช้ Service) ---
    $log_files = $this->logParser->getLogFilesByDate($start_timestamp, $end_timestamp);
    $log_content = '';
    foreach ($log_files as $log_file) {
      if (file_exists($log_file) && is_readable($log_file)) {
        $log_content .= file_get_contents($log_file);
      }
    }

    // กรอง log เฉพาะของ user คนนี้
    $user_log_content = $this->logParser->filterLogByUser($log_content, $ntrip_username);
    $formatted_log = $this->logParser->formatLogContent($user_log_content);

    // --- 5. ประมวลผลข้อมูลเพื่อส่งให้ Twig ---
    $rows = [];
    $total_cost_all = 0.0;

    if ($formatted_log && isset($formatted_log['user_summary'][$ntrip_username])) {
      foreach ($formatted_log['user_summary'][$ntrip_username] as $station => $data) {

        $data_in_mb = $data['bytes'] / 1048576;
        $custom_value_data = $data_in_mb * $data_rate;

        $minutes = $data['duration'] / 60;
        $custom_value_time = $minutes * $time_rate;

        $cost_value = ($cost_type === 'time') ? $custom_value_time : $custom_value_data;
        $total_cost_all += $cost_value;

        $rows[] = [
          'station' => $station,
          'duration' => $data['duration'] . ' sec',
          'bytes' => $this->logParser->formatBytes($data['bytes']), // เรียกใช้จาก service
          'cost' => number_format($cost_value, 2) . ' Baht',
        ];
      }
    }

    // --- 6. ส่งข้อมูลไปแสดงผลที่ Template ---
    return [
      '#theme' => 'ntrip_bill_page',
      '#title' => $this->t('NTRIP Bill for @user (@start to @end)', [
        '@user' => $ntrip_username,
        '@start' => $start_date_str,
        '@end' => $end_date_str,
      ]),
      '#rows' => $rows,
      '#total_cost_raw' => $total_cost_all, // ยอดรวม (ตัวเลข)
      '#total_cost_formatted' => number_format($total_cost_all, 2) . ' Baht', // ยอดรวม (ข้อความ)
      '#rate_info' => $this->t('Calculation based on: @type @ @rate Baht/@unit', [
        '@type' => ucfirst($cost_type),
        '@rate' => number_format($rate_display, 2),
        '@unit' => $unit
      ]),
      '#empty' => $this->t('No usage data found for this period.'),
      '#user_id' => $user->id(),
    ];
  }
/**
   * แสดงหน้าสรุปใบแจ้งหนี้ (Invoice Preview) ก่อนการชำระเงิน
   */
  public function paymentSummary(UserInterface $user) {
    // 1. ตรวจสอบสิทธิ์การเข้าถึง (เหมือน showBill)
    if ($this->currentUser->id() != $user->id() && !$this->currentUser->hasPermission('administer users')) {
      throw new AccessDeniedHttpException();
    }

    $ntrip_username = $user->get('field_ntrip_username')->value;
    if (empty($ntrip_username)) {
      return ['#markup' => $this->t('NTRIP username not found.')];
    }

    // 2. ตั้งค่าช่วงเวลาและดึง Rate (ดึงจาก Config ที่ Admin ตั้งไว้)
    $start_date_str = date('Y-m-01');
    $end_date_str = date('Y-m-d');
    $config = $this->config('ntrip_config_editor.settings');
    $time_rate = (float)($config->get('time_rate') ?? 0.2);
    $data_rate = (float)($config->get('data_rate') ?? 1.0);
    $cost_type = 'time'; // สามารถปรับให้ดึงจาก config ได้

    // 3. ประมวลผล Log และคำนวณยอด
    $start_timestamp = strtotime($start_date_str . ' 00:00:00');
    $end_timestamp = strtotime($end_date_str . ' 23:59:59');
    $log_files = $this->logParser->getLogFilesByDate($start_timestamp, $end_timestamp);
    
    $log_content = '';
    foreach ($log_files as $log_file) {
      if (file_exists($log_file) && is_readable($log_file)) {
        $log_content .= file_get_contents($log_file);
      }
    }

    $user_log_content = $this->logParser->filterLogByUser($log_content, $ntrip_username);
    $formatted_log = $this->logParser->formatLogContent($user_log_content);

    $items = [];
    $subtotal = 0.0;

    if ($formatted_log && isset($formatted_log['user_summary'][$ntrip_username])) {
      foreach ($formatted_log['user_summary'][$ntrip_username] as $station => $data) {
        // คำนวณตาม Logic ในโค้ดเดิมของคุณ
        $minutes = $data['duration'] / 60;
        $cost_value = ($cost_type === 'time') ? ($minutes * $time_rate) : (($data['bytes'] / 1048576) * $data_rate);
        $subtotal += $cost_value;

        $items[] = [
          'description' => $station, // จะแสดงเป็น BASET113
          'usage' => $data['duration'] . ' sec (' . $this->logParser->formatBytes($data['bytes']) . ')',
          'amount' => number_format($cost_value, 2),
        ];
      }
    }
    // --- ส่วนคำนวณยอดรวมสุทธิ (Logic เพิ่มเติม) ---
    $tax_rate = 0.07; // VAT 7%
    $tax_amount = $subtotal * $tax_rate;
    $total_all = $subtotal + $tax_amount;

    // 4. เตรียมข้อมูลสำหรับส่งไปที่ Template แบบทางการ
    return [
      '#theme' => 'ntrip_invoice_template',
      '#invoice_data' => [
        'invoice_no' => 'INV-' . date('Ymd') . '-' . $user->id(),
        'date' => date('d/m/Y'),
        'customer_name' => $user->getDisplayName(),
        'customer_email' => $user->getEmail(),
        'ntrip_user' => $ntrip_username,
        'items' => $items,
        'subtotal' => number_format($subtotal, 2), // ยอดก่อนภาษี
        'tax' => number_format($tax_amount, 2), // ยอดภาษี 7%
        'total' => number_format($total_all, 2), // ยอดรวมสุทธิ
        'period' => $start_date_str . ' to ' . $end_date_str,
      ],
    ];
  }
}