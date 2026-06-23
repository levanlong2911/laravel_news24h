"""
Smoke test cho API video pipeline giữa Laravel và Python.
Chạy: python test_video_api.py
Yêu cầu: pip install requests
"""
import requests

BASE_URL = "http://localhost:8000/api/video-jobs"  # đổi thành domain thật nếu test trên server
TOKEN = "PASTE_TOKEN_HERE"  # token mint từ Bước 3

HEADERS = {
    "Authorization": f"Bearer {TOKEN}",
    "Accept": "application/json",
}


def step(name):
    print(f"\n=== {name} ===")


def main():
    step("1. List jobs (status=script_ready)")
    r = requests.get(BASE_URL, headers=HEADERS, params={"status": "script_ready", "limit": 5})
    r.raise_for_status()
    jobs = r.json()["data"]
    print(jobs)

    if not jobs:
        print("Không có job nào ở trạng thái script_ready. Chạy `php artisan video:process-articles` trước.")
        return

    job_id = jobs[0]["id"]
    print(f"job_id = {job_id}")

    step("2. Claim job")
    r = requests.post(f"{BASE_URL}/{job_id}/claim", headers=HEADERS, json={"worker_id": "test-worker-1"})
    print(r.status_code, r.json())
    if r.status_code != 200:
        print("Claim thất bại -- job đã bị claim trước đó hoặc không tồn tại.")
        return

    step("3. Get full script payload")
    r = requests.get(f"{BASE_URL}/{job_id}", headers=HEADERS)
    r.raise_for_status()
    script = r.json()["data"]
    print(script)

    step("4. Update status -> rendering")
    r = requests.post(f"{BASE_URL}/{job_id}/status", headers=HEADERS, json={"status": "rendering"})
    print(r.status_code, r.json())

    step("5. Update status -> quality_check_passed (giả lập render xong)")
    r = requests.post(
        f"{BASE_URL}/{job_id}/status",
        headers=HEADERS,
        json={"status": "quality_check_passed", "cost_total": 0.4231},
    )
    print(r.status_code, r.json())

    step("6. Upload assets (video giả -- file rỗng .mp4 chỉ để test upload)")
    with open("dummy.mp4", "wb") as f:
        f.write(b"\x00" * 1024)  # file giả, không phải video thật

    with open("dummy.mp4", "rb") as f:
        files = {"video": ("dummy.mp4", f, "video/mp4")}
        data = {"metadata": '{"cost_total": 0.4231, "youtube_video_id": "TEST123"}'}
        r = requests.post(f"{BASE_URL}/{job_id}/assets", headers=HEADERS, files=files, data=data)
    print(r.status_code, r.json())

    step("7. Update status -> uploaded")
    r = requests.post(f"{BASE_URL}/{job_id}/status", headers=HEADERS, json={"status": "uploaded"})
    print(r.status_code, r.json())

    print("\n✅ Hoàn tất flow claim -> render -> quality check -> upload -> done")


if __name__ == "__main__":
    main()
