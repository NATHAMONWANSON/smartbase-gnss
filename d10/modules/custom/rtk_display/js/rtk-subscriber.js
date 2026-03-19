// File: js/rtk-subscriber.js
(function (Drupal) {
  'use strict';

  Drupal.behaviors.rtkSubscriber = {
    attach: function (context, settings) {
      // ตรวจสอบให้แน่ใจว่าโค้ดจะรันแค่ครั้งเดียวสำหรับ Element นี้
      const rtkWrapper = context.querySelector('#rtk-data-wrapper');
      if (!rtkWrapper || rtkWrapper.getAttribute('data-rtk-initialized')) {
        return;
      }
      rtkWrapper.setAttribute('data-rtk-initialized', 'true');

      // ===================================================================
      // ▼▼▼ ส่วนที่ 1: กรอกข้อมูลที่ได้จากคนที่ 1 ลงในนี้ ▼▼▼
      // ===================================================================
      const brokerUrl = 'wss://smartbase_2025.jirapoom.work/mqtt/'; //  WebSocket URL
      const topic = 'base/fix_status';               //  MQTT Topic
      const options = {
        clientId: 'drupal_web_client_' + Math.random().toString(16).substr(2, 8),
        // ถ้ามี username/password ให้เอา comment ออกแล้วใส่ค่า
        // username: 'your_username',
        // password: 'your_password',
      };
      // ===================================================================

      console.log('RTK Display: กำลังเชื่อมต่อ...');
      const client = mqtt.connect(brokerUrl, options);

      // เมื่อเชื่อมต่อสำเร็จ
      client.on('connect', () => {
        console.log('RTK Display: เชื่อมต่อสำเร็จ!');
        client.subscribe(topic, (err) => {
          if (!err) {
            console.log('RTK Display: ดักฟัง Topic:', topic);
            document.getElementById('rtk-status').innerText = 'Waiting for data...';
          }
        });
      });

      // เมื่อได้รับข้อความใหม่
      client.on('message', (topic, message) => {
        const messageStr = message.toString();
        console.log('RTK Display: ได้รับข้อมูล:', messageStr);

        // กรองเฉพาะข้อความที่เป็น JSON เท่านั้น
        if (messageStr.startsWith('{') && messageStr.endsWith('}')) {
          try {
            const data = JSON.parse(messageStr);
            // ตรวจสอบค่าของ data.Status เพื่อเปลี่ยนการแสดงผล
            if (data.Status === 'Fix Position') {
              // --- กรณีที่ 1: สถานะเป็น Fix Position ---
              const lat = data.Lat;
              const lon = data.Lon;
              const h = data.ALT;

              // ตรวจสอบว่ามีข้อมูล Lat/Lon/H ครบถ้วน
              if (lat !== undefined && lon !== undefined && h !== undefined) {
                document.getElementById('rtk-status').innerText = data.Status;
                document.getElementById('rtk-status').style.color = 'green'; // เปลี่ยนเป็นสีเขียว
                document.getElementById('rtk-lat').innerText = parseFloat(lat).toFixed(8);
                document.getElementById('rtk-lon').innerText = parseFloat(lon).toFixed(8);
                document.getElementById('rtk-h').innerText = h;
              }

            } else if (data.Status === 'Keep Waiting') {
              // --- กรณีที่ 2: สถานะเป็น Keep Waiting ---
              document.getElementById('rtk-status').innerText = data.Status;
              document.getElementById('rtk-status').style.color = '#f0ad4e'; // สีส้ม/เหลือง
              document.getElementById('rtk-lat').innerText = 'N/A'; // คืนค่าเป็น N/A
              document.getElementById('rtk-lon').innerText = 'N/A'; // คืนค่าเป็น N/A
              document.getElementById('rtk-h').innerText = 'N/A'; // คืนค่าเป็น N/A

            } else {
              // --- กรณีอื่นๆ (ถ้ามี) ---
              document.getElementById('rtk-status').innerText = data.Status || 'Unknown Status';
              document.getElementById('rtk-status').style.color = 'grey';
            }
          } catch (e) {
            console.error('RTK Display: ไม่สามารถ parse JSON ได้!', e);
          }
        }
      });

      // เมื่อเกิดข้อผิดพลาด
      client.on('error', (err) => {
        console.error('RTK Display: เกิดข้อผิดพลาดในการเชื่อมต่อ!', err);
        document.getElementById('rtk-status').innerText = 'Connection Error!';
        document.getElementById('rtk-status').style.color = 'red';
      });
    }
  };
})(Drupal);