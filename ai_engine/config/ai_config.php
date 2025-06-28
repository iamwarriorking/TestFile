<?php
return [
    'model_version' => 'v2',
    'batch_size' => 500, // Reduced for better performance
    'accuracy_threshold' => 0.05, // 5% improvement required
    'deal_engagement_threshold' => 0.12,
    'max_execution_time' => 300, // 5 minutes
    'memory_limit' => '256M',
    'cache_enabled' => true,
    'cache_ttl' => 3600, // 1 hour
    'log_level' => 'INFO',
    'rate_limit' => [
        'telegram_messages_per_minute' => 30,
        'api_calls_per_second' => 10,
        'max_concurrent_requests' => 5
    ],
    'prediction_settings' => [
        'months_ahead' => 3,
        'min_confidence' => 0.6,
        'max_price_change_percent' => 50,
        'min_price_history_points' => 5
    ],
    'clustering_settings' => [
        'min_users_for_clustering' => 10,
        'max_clusters' => 5,
        'convergence_tolerance' => 1e-4,
        'max_iterations' => 300
    ],
    'validation_settings' => [
        'cross_validation_folds' => 5,
        'test_split_ratio' => 0.2,
        'min_samples_per_fold' => 10
    ],
    'feature_engineering' => [
        'enable_volatility_features' => true,
        'enable_seasonal_features' => true,
        'enable_category_features' => true,
        'enable_festival_features' => true,
        'enable_momentum_features' => true
    ],
    'database_optimization' => [
        'batch_insert_size' => 100,
        'query_timeout' => 30,
        'connection_pool_size' => 5
    ]
];
?>