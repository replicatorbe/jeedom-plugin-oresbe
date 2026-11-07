<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * Dessinée ici plutôt que déposée en binaire opaque : on peut la relire et la
 * refaire. Le dessin est fait en 1024 puis réduit en 256, pour des bords
 * lissés que GD ne produit pas sur un remplissage direct.
 *
 * Une maison dont la lumière s'est éteinte, et un éclair barré : une coupure
 * de courant à l'adresse. Un dessin générique, pas le logo du gestionnaire.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$jaune = imagecolorallocate($grand, 0xF5, 0xB0, 0x1E);
$rouge = imagecolorallocate($grand, 0xD6, 0x33, 0x2B);
$gris  = imagecolorallocate($grand, 0x36, 0x44, 0x4B);
$clair = imagecolorallocate($grand, 0x5B, 0x6B, 0x73);
$blanc = imagecolorallocate($grand, 0xFF, 0xFF, 0xFF);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };
$poly = function ($_points, $_couleur) use ($grand, $e) {
    imagefilledpolygon($grand, array_map($e, $_points), $_couleur);
};

/* La maison : un toit, des murs, une fenêtre éteinte. */
$poly(array(20, 118, 128, 22, 236, 118, 214, 118, 128, 44, 42, 118), $gris);
imagefilledrectangle($grand, $e(44), $e(110), $e(212), $e(234), $gris);
imagefilledrectangle($grand, $e(62), $e(140), $e(110), $e(188), $clair);
imagefilledrectangle($grand, $e(78), $e(196), $e(110), $e(234), $clair);

/* L'éclair, jaune, sur la droite de la façade. */
$poly(array(176, 112, 134, 186, 162, 186, 146, 238, 200, 160, 172, 160, 190, 112), $jaune);

/* La barre rouge : le courant est coupé. */
imagesetthickness($grand, $e(14));
imageline($grand, $e(122), $e(116), $e(212), $e(232), $blanc);
imagesetthickness($grand, $e(9));
imageline($grand, $e(122), $e(116), $e(212), $e(232), $rouge);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/oresbe_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
