<?php

use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Timatic\JsonApiSdk\Services\GeneratedFileWriter;

test('removing generated directories keeps the foundation', function () {
    // Given
    $outputDir = sys_get_temp_dir().'/'.uniqid('sdk');
    mkdir("{$outputDir}/src/Requests/Removed", 0755, true);
    mkdir("{$outputDir}/src/Foundation", 0755, true);
    touch("{$outputDir}/src/Requests/Removed/GetRemovedRequest.php");
    touch("{$outputDir}/src/Foundation/Factory.php");
    $config = new Config(connectorName: 'TestConnector', namespace: 'Test\\Sdk', dtoNamespaceSuffix: 'DataTransferObjects');

    // When
    (new GeneratedFileWriter($config, $outputDir))->removeGeneratedDirectories();

    // Then
    expect("{$outputDir}/src/Requests")->not->toBeDirectory()
        ->and("{$outputDir}/src/Foundation/Factory.php")->toBeFile();
});
