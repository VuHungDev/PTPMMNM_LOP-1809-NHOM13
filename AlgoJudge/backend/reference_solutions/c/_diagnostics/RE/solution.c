#include <stdlib.h>

/*
 * Ví dụ lớp học RE có chủ đích: abort() kết thúc bất thường một cách xác định.
 * Không dựa vào truy cập sai bộ nhớ hay hành vi không xác định.
 * Đây không phải lời giải bài tập. Chỉ chạy trong bộ chấm Docker có giới hạn.
 * Không chạy trực tiếp trên máy chủ.
 */
int main(void) {
    abort();
}
