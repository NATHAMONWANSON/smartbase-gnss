(function ($, Drupal, once) {
  Drupal.behaviors.gnssMonitor = {
    attach: function (context, settings) {
      $(once('gnss-monitor', '.gnss-dashboard', context)).each(function () {
        
        // --- 0. Settings & Globals ---
        const apiUrl = 'https://smartbase_2025.jirapoom.work/api/gnss';
        let chartS4C, chartROTI;
        let updateInterval;
        let isLiveMode = true;

        // ✅ จุดแก้ที่ 1: บังคับให้ Highcharts ใช้เวลา UTC เสมอ
        Highcharts.setOptions({
            time: {
                useUTC: true
            }
        });

        const satColors = {
            'G01': '#E6194B', 'G02': '#3CB44B', 'G03': '#FFE119', 'G04': '#4363D8',
            'G05': '#F58231', 'G06': '#911EB4', 'G07': '#42D4F4', 'G08': '#F032E6',
            'G09': '#BFEF45', 'G10': '#FABED4', 'G11': '#469990', 'G12': '#DCBEFF',
            'G13': '#9A6324', 'G14': '#FFFAC8', 'G15': '#800000', 'G16': '#AAFFC3',
            'G17': '#808000', 'G18': '#FFD8B1', 'G19': '#000075', 'G20': '#A9A9A9',
            'G21': '#000000', 'G22': '#808080', 'G23': '#FF5733', 'G24': '#C70039',
            'G25': '#900C3F', 'G26': '#581845', 'G27': '#1B4F72', 'G28': '#28B463',
            'G29': '#D35400', 'G30': '#7D3C98', 'G31': '#2E86C1', 'G32': '#17A589'
        };

        function getSatColor(prn) {
            return satColors[prn] || '#' + Math.floor(Math.random()*16777215).toString(16);
        }

        // --- 1. Date Picker ---
        // ✅ จุดแก้ที่ 2: หาวันที่ปัจจุบันในมาตรฐาน UTC เพื่อกำหนดเป็นวันสูงสุดในปฏิทิน
        const todayUTCStr = new Date().toISOString().split('T')[0];
        const datePicker = flatpickr("#date-picker", {
            dateFormat: "Y-m-d",
            maxDate: todayUTCStr,  // เปลี่ยนจาก "today" เป็นวันที่ UTC
            onChange: function(selectedDates, dateStr) {
                // ✅ เพิ่ม if เช็คว่าต้องมีวันที่เท่านั้น ถึงจะดึงข้อมูล
                if (dateStr && dateStr !== "") {
                stopLiveMode();
                loadHistory(dateStr);
                }
            }
        });

        $('#btn-reset').click(function() {
            startLiveMode();
            datePicker.clear();
            $(this).addClass('hidden');
        });

        // --- 2. Create Professional Chart (Fixed Spacing) ---
        function createChart(containerId, title, yTitle) {
            const now = new Date().getTime();
            const start24h = now - (24 * 60 * 60 * 1000);

            if (!$('#' + containerId).length) return null;

            const initialSeries = Object.keys(satColors).sort().map(satId => ({
                name: satId,
                data: [], 
                color: satColors[satId],
                visible: true
            }));

            return Highcharts.chart(containerId, {
                chart: { 
                    type: 'line',
                    zoomType: 'x',
                    animation: false,
                    // ✅ จุดสำคัญ 1: พื้นที่ด้านบน (เพดาน) ตั้งไว้ 160px
                    // ค่านี้จะดันกราฟลงไปข้างล่าง ทำให้มีที่ว่างเหลือเฟือสำหรับ Header
                    marginTop: 160,    
                    marginBottom: 60,
                    marginLeft: 80,
                    marginRight: 30,
                    style: { fontFamily: '"Segoe UI", Roboto, sans-serif' },
                    backgroundColor: '#ffffff',
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    borderRadius: 5
                },
                title: { 
                    text: title, 
                    align: 'center',
                    style: { fontSize: '18px', fontWeight: 'bold', color: '#333' },
                    // ✅ จุดสำคัญ 2: ตำแหน่งชื่อกราฟ อยู่เกือบติดขอบบน
                    y: 15,
                    margin: 0 
                },
                credits: { enabled: false },
                xAxis: { 
                    title: { 
                        text: 'UTC(second)', 
                        style: { fontWeight: '600', color: '#444', fontSize: '12px' },
                        margin: 10
                    },
                    type: 'datetime', 
                    gridLineWidth: 1,
                    gridLineColor: '#f0f0f0',
                    lineColor: '#ccd6eb',
                    tickColor: '#ccd6eb',
                    labels: { style: { color: '#666', fontSize: '11px' }, y: 20 },
                    crosshair: true,
                    min: start24h,
                    max: now,
                    dateTimeLabelFormats: { day: '%e %b', hour: '%H:%M' }
                },
                yAxis: { 
                    title: { 
                        text: yTitle, 
                        style: { fontWeight: '600', color: '#444' },
                        margin: 20
                    },
                    min: 0,
                    max: 1.0, // ล็อคความสูง 1.0
                    tickInterval: 0.1, // ขีดเส้นทุก 0.1
                    gridLineColor: '#e6e6e6',
                    labels: { 
                        format: '{value:.1f}',
                        style: { color: '#666', fontSize: '11px' },
                        align: 'right',
                        x: -10 
                    }
                },
                legend: {
                    enabled: true,
                    layout: 'horizontal',
                    align: 'center',
                    verticalAlign: 'top',
                    floating: true, 
                    // ✅ จุดสำคัญ 3: ตำแหน่งกล่องสีดาวเทียม
                    // ตั้งไว้ที่ 40 คืออยู่ใต้ชื่อกราฟนิดเดียว (ชิดกัน)
                    // และเพราะ marginTop เราเยอะ (160) มันเลยจะห่างจากตัวกราฟข้างล่างโดยอัตโนมัติ
                    y: 40,          
                    backgroundColor: '#ffffff',
                    borderColor: '#dddddd',
                    borderWidth: 1,
                    borderRadius: 3,
                    padding: 8,
                    itemDistance: 10,
                    itemStyle: { fontSize: '11px', fontWeight: 'normal', color: '#333' },
                    symbolWidth: 12, 
                    symbolHeight: 12,
                    symbolRadius: 2  
                },
                tooltip: { 
                    shared: true, 
                    useHTML: true,
                    headerFormat: '<div style="font-size:11px; color:#666; margin-bottom:5px">{point.key}</div><table style="font-size:12px">',
                    pointFormat: '<tr><td style="color: {series.color}; padding-right:5px">■ {series.name}: </td>' +
                                 '<td style="text-align: right; font-weight:bold">{point.y:.3f}</td></tr>',
                    footerFormat: '</table>',
                    valueDecimals: 3,
                    backgroundColor: 'rgba(255, 255, 255, 0.95)',
                    borderColor: '#ccc',
                    borderRadius: 4,
                    shadow: { opacity: 0.1 }
                },
                plotOptions: {
                    series: {
                        lineWidth: 2,
                        connectNulls: false, // ✅ เพิ่มบรรทัดนี้: ไม่ให้ลากเส้นข้ามจุดที่เป็น null
                        marker: { enabled: false },
                        states: { 
                            hover: { lineWidth: 3 }
                        },
                        animation: false
                    }
                },
                series: initialSeries 
            });
        }

        // --- 3. Process Data ---
        function processData(rawData, valueKey) {
            const seriesData = {};
            // กำหนดระยะเวลาสูงสุดที่จะยอมให้ลากเส้นต่อกัน (5 นาที)
            const MAX_GAP_MS = 5 * 60 * 1000;
            
            if(Array.isArray(rawData)) {
                rawData.forEach(row => {
                    let dateTimeStr = row.date_utc + 'T' + row.time_utc + 'Z'; 
                    let timestamp = new Date(dateTimeStr).getTime();
                    let val = parseFloat(row[valueKey]);
                    let satId = row.sat_id || row.prn;

                    // ✅ 1. ถ้ายังไม่มีดาวเทียมดวงนี้ ให้สร้าง Array ว่างเตรียมไว้
                    if (!seriesData[satId]) { 
                        seriesData[satId] = [];
                    }

                    // ✅ 2. สร้างตัวแปรอ้างอิง เพื่อลดการพิมพ์ seriesData[satId] ซ้ำๆ
                    let satData = seriesData[satId];

                    // ✅ 3. ถ้ามีข้อมูลก่อนหน้าแล้ว ให้เช็คระยะห่างเวลา (Gap)
                    if (satData.length > 0) {
                        let lastTimestamp = satData[satData.length - 1][0];

                        // ถ้าระยะห่างมากกว่า MAX_GAP_MS ให้แทรกจุด null เพื่อตัดเส้นกราฟ
                        if (timestamp - lastTimestamp > MAX_GAP_MS) {
                            satData.push([lastTimestamp + 1000, null]);
                        }
                    }

                    // ✅ 3.1 Push ข้อมูลล่าสุดเข้าไปเสมอ
                    satData.push([timestamp, val]);
                });
            }

            let finalSeries = [];
            Object.keys(seriesData).sort().forEach(satId => {
                if (seriesData[satId].length > 0) {
                    finalSeries.push({
                        name: satId,
                        data: seriesData[satId],
                        color: getSatColor(satId)
                    });
                }
            });
            return finalSeries;
        }
        // --- 4. Update Function ---
        function updateSeries(chartInstance, newSeriesData) {
            if (!chartInstance) return;

            newSeriesData.forEach(newS => {
                let existingSeries = chartInstance.series.find(s => s.name === newS.name);
                if (existingSeries) {
                    existingSeries.setData(newS.data, false, false, false); 
                } else {
                    chartInstance.addSeries(newS, false);
                }
            });
            chartInstance.redraw();
        }

        // --- 5. Main Loop ---
        function updateCharts() {
            if (!isLiveMode) return;

            const now = new Date().getTime();
            const start24h = now - (24 * 60 * 60 * 1000);
            
            if(chartS4C) chartS4C.xAxis[0].setExtremes(start24h, now, false);
            if(chartROTI) chartROTI.xAxis[0].setExtremes(start24h, now, false);

            Promise.all([
                fetch(`${apiUrl}/s4c`).then(res => res.json()).catch(e => ({error: true})),
                fetch(`${apiUrl}/roti`).then(res => res.json()).catch(e => ({error: true}))
            ]).then(([s4cData, rotiData]) => {
                
                $('#spinner-s4c, #spinner-roti').addClass('hidden');

                if(!s4cData.error) {
                    const s4cSeries = processData(s4cData, 's4c');
                    updateSeries(chartS4C, s4cSeries);
                }

                if(!rotiData.error) {
                    const rotiSeries = processData(rotiData, 'roti');
                    updateSeries(chartROTI, rotiSeries);
                }
            });
        }

        // --- 6. Load History ---
        function loadHistory(dateStr) {
            // ✅ ดักไว้อีกชั้น ป้องกันไม่ให้ส่งค่าว่างไป API
            if (!dateStr) return;

            $('#spinner-s4c, #spinner-roti').removeClass('hidden');
            $('#btn-reset').removeClass('hidden');

            const requestBody = JSON.stringify({ date: dateStr });
            
            Promise.all([
                fetch(`${apiUrl}/s4c/history`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: requestBody }).then(r=>r.json()).catch(e=>({error:true})),
                fetch(`${apiUrl}/roti/history`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: requestBody }).then(r=>r.json()).catch(e=>({error:true}))
            ]).then(([s4cData, rotiData]) => {

                // ✅ สิ่งที่แก้: คำนวณเวลา 00:00 ถึง 23:59 ของวันที่เลือกเป็น UTC
                const startOfDay = new Date(dateStr + 'T00:00:00Z').getTime();
                const endOfDay = new Date(dateStr + 'T23:59:59Z').getTime();
                
                // ✅ สิ่งที่แก้: บังคับแกน X ให้ซูมไปที่วันที่เลือกทันที ต่อให้ไม่มีข้อมูลก็จะไม่ค้างวันที่เดิม
                if(chartS4C) chartS4C.xAxis[0].setExtremes(startOfDay, endOfDay, false); 
                if(chartROTI) chartROTI.xAxis[0].setExtremes(startOfDay, endOfDay, false);

                if(chartS4C) chartS4C.series.forEach(s => s.setData([], false));
                if(chartROTI) chartROTI.series.forEach(s => s.setData([], false));

                if(!s4cData.error) {
                    updateSeries(chartS4C, processData(s4cData, 's4c'));
                } else {
                    console.error("S4C History Error:", s4cData);
                }
                
                if(!rotiData.error) {
                    updateSeries(chartROTI, processData(rotiData, 'roti'));
                } else {
                    console.error("ROTI History Error:", rotiData);
                }
                
                $('#spinner-s4c, #spinner-roti').addClass('hidden');
            });
        }

        function startLiveMode() {
            isLiveMode = true;
            updateCharts(); 
            if (updateInterval) clearInterval(updateInterval);
            updateInterval = setInterval(updateCharts, 60000);
        }

        function stopLiveMode() {
            isLiveMode = false;
            clearInterval(updateInterval);
        }

        $('#btn-show-all').click(() => {
            if(chartS4C) { chartS4C.series.forEach(s => s.setVisible(true, false)); chartS4C.redraw(); }
            if(chartROTI) { chartROTI.series.forEach(s => s.setVisible(true, false)); chartROTI.redraw(); }
        });

        $('#btn-hide-all').click(() => {
            if(chartS4C) { chartS4C.series.forEach(s => s.setVisible(false, false)); chartS4C.redraw(); }
            if(chartROTI) { chartROTI.series.forEach(s => s.setVisible(false, false)); chartROTI.redraw(); }
        });

        if ($('#chart-s4c').length) {
            chartS4C = createChart('chart-s4c', 'S4C Index', 'S4C');
        }
        if ($('#chart-roti').length) {
            chartROTI = createChart('chart-roti', 'Rate of TEC change index (ROTI)', 'ROTI (TECU/min)');
        }

        startLiveMode();

      });
    }
  };
})(jQuery, Drupal, once);