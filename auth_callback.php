<?php
// Indirizzo di ritorno del login Google (registrato nella Google Cloud Console):
// passa tutto a _Ykan.php, che fa il lavoro vero (vedi ykanAuthGate).
$_GET['auth'] = 'callback';
require __DIR__ . '/_Ykan.php';
