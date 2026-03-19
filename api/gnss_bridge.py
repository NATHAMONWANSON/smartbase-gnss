from flask import Flask, jsonify, request
from flask_cors import CORS  # ต้องลง pip install flask-cors ก่อน
from flask_compress import Compress
import pymysql
from sshtunnel import SSHTunnelForwarder
from datetime import datetime, timedelta

app = Flask(__name__)

# เปิดใช้งาน Gzip Compression บีบอัดข้อมูลก่อนส่ง
Compress(app)

# เปิดอนุญาต CORS ให้ทุกโดเมนเข้าถึงได้ (สำคัญมากสำหรับ JS)
CORS(app, resources={r"/*": {"origins": "*"}})

# --- Configuration ---
SSH_HOST = '161.246.18.205'
SSH_PORT = 22
SSH_USER = 'tts'
SSH_PASS = 'ttsproj'

DB_USER = "g2u-admin"
DB_PASS = "cs2rg-T113"
DB_NAME = "SMBASE"
DB_HOST = "127.0.0.1"
DB_PORT = 3306

def get_data_from_db(table_name, start_time, end_time):
    tunnel = None
    connection = None
    try:
        # 1. สร้าง SSH Tunnel
        tunnel = SSHTunnelForwarder(
            (SSH_HOST, SSH_PORT),
            ssh_username=SSH_USER,
            ssh_password=SSH_PASS,
            remote_bind_address=(DB_HOST, DB_PORT)
        )
        tunnel.start()

        # 2. เชื่อมต่อ Database ผ่าน Tunnel
        connection = pymysql.connect(
            host='127.0.0.1',
            port=tunnel.local_bind_port,
            user=DB_USER,
            password=DB_PASS,
            database=DB_NAME,
            cursorclass=pymysql.cursors.DictCursor
        )

        with connection.cursor() as cursor:
            # Query ดึงข้อมูลตามช่วงเวลา
            query = f"""
                SELECT * FROM {table_name}
                WHERE TIMESTAMP(date_utc, time_utc) BETWEEN %s AND %s
                AND SECOND(time_utc) = 0
                ORDER BY date_utc ASC, time_utc ASC
            """
            cursor.execute(query, (start_time, end_time))
            result = cursor.fetchall()

        return result

    except Exception as e:
        print(f"Error: {e}")
        return None
    finally:
        # ปิดการเชื่อมต่อเสมอเมื่อเสร็จงาน
        if connection: connection.close()
        if tunnel: tunnel.stop()

# --- Routes ---

@app.route('/')
def home():
    return "GNSS API Service Running..."
@app.route('/api/gnss/<type>', methods=['GET'])
def get_latest_24h(type):
    """ ดึงข้อมูล 24 ชม. ล่าสุด (GET) """
    if type not in ['s4c', 'roti']:
        return jsonify({"error": "Invalid type"}), 400

    table_name = f"gnss_{type}"
    now_utc = datetime.utcnow()
    start_time = now_utc - timedelta(hours=24)

    data = get_data_from_db(table_name, start_time, now_utc)
    if data is None: return jsonify({"error": "Database Error"}), 500
    return jsonify(data)

@app.route('/api/gnss/<type>/history', methods=['POST'])
def get_history(type):
    """ ดึงข้อมูลตามวันที่เลือก (POST) """
    if type not in ['s4c', 'roti']:
        return jsonify({"error": "Invalid type"}), 400

    req_data = request.get_json()
    date_str = req_data.get("date") # รับค่าเป็น 'YYYY-MM-DD'

    if not date_str:
        return jsonify({"error": "Date required"}), 400

    try:
        # กำหนดช่วงเวลา 00:00 - 23:59 ของวันที่เลือก
        start_time = datetime.strptime(f"{date_str} 00:00:00", "%Y-%m-%d %H:%M:%S")
        end_time = datetime.strptime(f"{date_str} 23:59:59", "%Y-%m-%d %H:%M:%S")

        table_name = f"gnss_{type}"
        data = get_data_from_db(table_name, start_time, end_time)

        if data is None: return jsonify({"error": "Database Error"}), 500
        return jsonify(data)

    except Exception as e:
        return jsonify({"error": str(e)}), 500

if __name__ == '__main__':
    # รันบน Port 5000
    app.run(host='0.0.0.0', port=5000)
