(function (Drupal) {
  Drupal.behaviors.s4cGraph = {
    attach: function (context, settings) {
      if (context !== document) return;

      console.log("S4C Graph (API Mode): Initializing...");

      // --- 1. ตั้งค่าพื้นฐาน ---
      const apiBase = 'http://192.168.1.21:5000'; // IP ของเครื่อง Server
      let updateInterval = null;

      // --- 2. ฟังก์ชันสร้างกราฟ ---
      function createChart(canvasId, label) {
          const canvas = document.getElementById(canvasId);
          if (!canvas) return null;
          
          const ctx = canvas.getContext('2d');
          return new Chart(ctx, {
            type: 'line',
            data: { datasets: [] },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              animation: false, // ปิด Animation ตอนอัปเดตข้อมูลจะได้ไม่กระตุก
              plugins: {
                title: { display: true, text: label },
                tooltip: { mode: 'nearest', intersect: false },
                zoom: {
                    zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' },
                    pan: { enabled: true, mode: 'x' }
                }
              },
              scales: {
                x: {
                  type: 'time',
                  time: { 
                      unit: 'minute', 
                      displayFormats: { minute: 'HH:mm' },
                      tooltipFormat: 'HH:mm:ss' 
                  },
                  title: { display: true, text: 'Time (UTC)' }
                },
                y: { 
                    title: { display: true, text: 'Value' }, 
                    beginAtZero: true 
                }
              }
            }
          });
      }

      // สร้าง Instance ของกราฟ
      const chartS4 = createChart('s4Chart', 'S4 Index (Scintillation)');
      const chartRoti = createChart('rotiChart', 'ROTI Index');

      // ปุ่ม Reset Zoom
      const btnReset = document.getElementById('btn-reset-zoom');
      if(btnReset) {
          btnReset.addEventListener('click', function() {
              if(chartS4) chartS4.resetZoom();
              if(chartRoti) chartRoti.resetZoom();
          });
      }

      // --- 3. ฟังก์ชันแปลงข้อมูล (Process Data) ---
      const processData = (dataList, valueKey) => {
          // *** จุดที่แก้: ป้องกัน error ถ้า dataList เป็น null หรือ undefined ***
          if(!dataList || !Array.isArray(dataList)) {
              console.warn(`Data for ${valueKey} is not an array:`, dataList);
              return [];
          }

          const satellites = {};
          
          dataList.forEach(row => {
              const prn = row.prn;
              const time = new Date(row.datetime).getTime(); // แปลงเวลา
              const val = parseFloat(row[valueKey]); // ดึงค่า (s4 หรือ roti)

              if (!satellites[prn]) satellites[prn] = [];
              satellites[prn].push({ x: time, y: val });
          });

          const datasets = [];
          // ชุดสีสำหรับเส้นกราฟแต่ละ PRN
          const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E7E9ED', '#71B37C'];
          let idx = 0;
          
          // เรียงตามชื่อดาวเทียม (G01, G02...)
          Object.keys(satellites).sort().forEach(prn => {
              datasets.push({
                  label: prn,
                  data: satellites[prn],
                  borderColor: colors[idx % colors.length],
                  backgroundColor: 'transparent',
                  borderWidth: 2,
                  pointRadius: 0, // ซ่อนจุดเพื่อให้กราฟดูสะอาด (โชว์เมื่อเอาเมาส์ชี้)
                  pointHoverRadius: 5,
                  tension: 0.1 // ความโค้งของเส้นเล็กน้อย
              });
              idx++;
          });
          return datasets;
      };

      // --- 4. ฟังก์ชันดึงข้อมูล (Fetch Data) ---
      const fetchData = () => {
          console.log("Fetching data from API...");
          
          Promise.all([
            fetch(`${apiBase}/gnss/s4c`).then(res => res.json()),
            fetch(`${apiBase}/gnss/roti`).then(res => res.json())
          ])
          .then(([s4Res, rotiRes]) => {
              // *** จุดที่แก้สำคัญมาก! ***
              // API ส่งมาเป็น { "s4": [...] } เราต้องดึง .s4 ออกมา
              // ถ้า API เปลี่ยนใจส่ง Array ล้วนๆ เราก็ใช้ s4Res ได้เลย (Code แบบ Defensive)
              const s4DataArray = s4Res.s4 ? s4Res.s4 : s4Res;
              const rotiDataArray = rotiRes.roti ? rotiRes.roti : rotiRes;

              console.log(`Data Received -> S4: ${s4DataArray.length} rows, ROTI: ${rotiDataArray.length} rows`);

              // อัปเดตกราฟ S4
              if (chartS4) {
                  chartS4.data.datasets = processData(s4DataArray, 's4');
                  chartS4.update('none'); // mode 'none' เพื่อประสิทธิภาพ
              }

              // อัปเดตกราฟ ROTI
              if (chartRoti) {
                  chartRoti.data.datasets = processData(rotiDataArray, 'roti');
                  chartRoti.update('none');
              }
          })
          .catch(error => {
              console.error("API Fetch Error:", error);
          });
      };

      // --- 5. เริ่มทำงาน ---
      
      // ดึงข้อมูลครั้งแรกทันที
      fetchData();

      // ตั้งเวลาดึงข้อมูลใหม่ทุกๆ 5 วินาที (Real-time)
      if (updateInterval) clearInterval(updateInterval);
      updateInterval = setInterval(fetchData, 5000); 
    }
  };
})(Drupal);