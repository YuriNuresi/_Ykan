<?php
/**
 * Entry point for ykan.portale3d.it — sends visitors to the _Ykan board.
 * (The board and this file are protected by Basic Auth via .htaccess;
 *  mcp.php is intentionally excluded so the claude.ai connector keeps working.)
 */
header('Location: _Ykan.php');
exit;
