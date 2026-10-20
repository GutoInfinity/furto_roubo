<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
fb_db()->exec(file_get_contents(FB_ROOT.'/database/atualizacao-conversas.sql'));
echo "Conversas atualizadas. Historico anterior preservado.\n";
