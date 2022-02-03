<?php

require_once('/home/bolshoy/vendor/autoload.php');

$document = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

$sheet = $document->setActiveSheetIndex(0);

$startColumn = 1;
$startLine = 1;

$headers = ['storeCode', 'Address', 'TM'];

$currentColumn = $startColumn;

foreach ($headers as $column) {
    $sheet->getStyleByColumnAndRow($currentColumn, $startLine)
          ->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()
          ->setRGB('4dbf62');

    $sheet->setCellValueByColumnAndRow($currentColumn, $startLine, $column);

    $currentColumn++;
}

$startLine++;

foreach ($toSave as $row){

    $currentColumn = $startColumn;
    $checkLine = $startLine-1;

    $upcell = $sheet->getCellByColumnAndRow($currentColumn, $checkLine)->getValue();

    $sheet->setCellValueByColumnAndRow($currentColumn, $startLine, $row['storeCode']);

    $currentColumn++;

    $upcell = $sheet->getCellByColumnAndRow($currentColumn, $checkLine)->getValue();

    $sheet->setCellValueByColumnAndRow($currentColumn, $startLine, $row['address']);

    $currentColumn++;

    $upcell = $sheet->getCellByColumnAndRow($currentColumn, $checkLine)->getValue();

    $sheet->setCellValueByColumnAndRow($currentColumn, $startLine, $row['tm']);



    $startLine++;

}





$objWriter = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($document, 'Xls');
$objWriter->save($tableFolder);