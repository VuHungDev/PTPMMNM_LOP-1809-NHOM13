/*
 * Ví dụ lớp học TLE có chủ đích: vòng lặp vô hạn, không xuất dữ liệu.
 * Biến volatile ngăn bỏ vòng lặp; số unsigned tràn theo quy tắc của C.
 * Đây không phải lời giải bài tập. Chỉ chạy trong Docker với timeout cứng
 * và giới hạn CPU/bộ nhớ. Không chạy trực tiếp trên máy chủ.
 */
int main(void) {
    volatile unsigned long counter = 0;
    for (;;) {
        ++counter;
    }
}
