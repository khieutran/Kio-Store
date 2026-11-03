<?php
/**
 * Phụ thuộc của editor.js (không có bước build nên tự khai báo bằng tay).
 *
 * Khi block.json trỏ "editorScript": "file:./editor.js", WordPress đọc file *.asset.php cùng
 * tên để biết script cần nạp sau những gói nào của trình soạn thảo, và version để xoá cache.
 *
 * @package KioStore
 */

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
	'version'      => '0.1.0',
);
