<?php
require_once __DIR__ . '/IQRCodeProvider.php';
require_once __DIR__ . '/PHPQRCode/qrlib.php';

use RobThree\Auth\Providers\Qr\IQRCodeProvider;

class CustomQRCodeProvider implements IQRCodeProvider
{
    public function getMimeType()
    {
        return 'image/png';
    }

    public function getQRCodeImage($qrtext, $size)
    {
        ob_start();
        QRcode::png($qrtext, false, QR_ECLEVEL_L, 10, 2);
        $imageData = ob_get_contents();
        ob_end_clean();
        return $imageData;
    }
}
