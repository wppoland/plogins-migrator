<?php return array(
    'root' => array(
        'name' => 'wp-php-toolkit/reprint-server-plugin',
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'reference' => NULL,
        'type' => 'project',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'wp-php-toolkit/reprint-exporter' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => 'dev-feature/lazy-reprint-server-utils',
            ),
        ),
        'wp-php-toolkit/reprint-server' => array(
            'pretty_version' => 'dev-feature/lazy-reprint-server-utils',
            'version' => 'dev-feature/lazy-reprint-server-utils',
            'reference' => '57cc3844bfe2644c9a6b500ed0044f4f8ecacdfa',
            'type' => 'library',
            'install_path' => __DIR__ . '/../wp-php-toolkit/reprint-server',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'wp-php-toolkit/reprint-server-plugin' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'reference' => NULL,
            'type' => 'project',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
