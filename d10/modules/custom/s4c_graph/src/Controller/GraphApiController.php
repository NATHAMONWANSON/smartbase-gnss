<?php

namespace Drupal\s4c_graph\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Database\Database;

class GraphApiController extends ControllerBase {

  public function getData() {
    $s4_data = [];
    $roti_data = [];
    $debug_msg = 'No Error';
    $row_count_s4 = 0;
    $row_count_roti = 0;

    // ตั้งค่าเวลาย้อนหลัง 24 ชั่วโมง (UTC)
    $cutoff_time = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));

    try {
      $database = \Drupal::database();

      // ==========================================
      // 1. ดึงข้อมูล S4C (จากตาราง SMBASE.gnss_s4c)
      // ==========================================
      $sql_s4 = "
        SELECT prn, s4c, date_utc, time_utc
        FROM SMBASE.gnss_s4c 
        WHERE CONCAT(date_utc, ' ', time_utc) >= :cutoff
        ORDER BY date_utc ASC, time_utc ASC
      ";
      
      $result_s4 = $database->query($sql_s4, [':cutoff' => $cutoff_time]);

      foreach ($result_s4 as $row) {
        $timestamp = strtotime($row->date_utc . ' ' . $row->time_utc) * 1000; // แปลงเป็น ms
        
        // ข้อมูล S4C มีตัว G อยู่แล้ว (เช่น G19) ใช้ได้เลย
        $s4_data[] = [
          'prn' => $row->prn,
          'ts'  => $timestamp,
          'val' => (float)$row->s4c,
        ];
        $row_count_s4++;
      }

      // ==========================================
      // 2. ดึงข้อมูล ROTI (จากตาราง SMBASE.gnss_roti)
      // ==========================================
      $sql_roti = "
        SELECT prn, roti, date_utc, time_utc
        FROM SMBASE.gnss_roti
        WHERE CONCAT(date_utc, ' ', time_utc) >= :cutoff
        ORDER BY date_utc ASC, time_utc ASC
      ";

      $result_roti = $database->query($sql_roti, [':cutoff' => $cutoff_time]);

      foreach ($result_roti as $row) {
        $timestamp = strtotime($row->date_utc . ' ' . $row->time_utc) * 1000;

        // *** สำคัญ: แก้ไข PRN ของ ROTI ให้มีตัว 'G' นำหน้า ***
        // ถ้า prn เป็นเลข "18" เราจะแก้เป็น "G18" (ถ้าเลขหลักเดียว "5" -> "G05")
        $raw_prn = trim($row->prn);
        if (is_numeric($raw_prn)) {
            // เติม G และ 0 ข้างหน้าให้ครบ 2 หลัก (เช่น 5 -> G05, 18 -> G18)
            $formatted_prn = 'G' . str_pad($raw_prn, 2, '0', STR_PAD_LEFT);
        } else {
            $formatted_prn = $raw_prn; // ถ้ามี G อยู่แล้วก็ใช้เลย
        }

        $roti_data[] = [
          'prn' => $formatted_prn,
          'ts'  => $timestamp,
          'val' => (float)$row->roti,
        ];
        $row_count_roti++;
      }

    } catch (\Exception $e) {
      $debug_msg = $e->getMessage();
    }

    // ส่งข้อมูลกลับ
    return new JsonResponse([
      's4' => $s4_data,
      'roti' => $roti_data,
      'debug_info' => [
        'status' => $debug_msg,
        'cutoff_time' => $cutoff_time,
        's4_count' => $row_count_s4,
        'roti_count' => $row_count_roti,
        'database_target' => 'SMBASE'
      ]
    ]);
  }
}