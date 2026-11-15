<?php
// Ví dụ lớp học cố ý gây RE: ngoại lệ không được bắt.
// Chỉ chạy trong Docker sandbox của bộ chấm, không chạy trên máy chủ.
throw new RuntimeException('Intentional classroom runtime error');
