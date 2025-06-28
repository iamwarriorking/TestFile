<?php
// Define the constant for login redirect URL
define('LOGIN_REDIRECT', '/auth/login.php');

$securityConfig = [
    'csrf' => [
        'token_length' => 32,
        'expiry_time' => 3600 // 1 hour
    ],
    'jwt' => [
        'secret' => '4b042d98d52d449fc87fef98785e07b5a269c5ab4973b145017f77c1f272a0d5c2d739d48b72fbeb758564f0afbac0055d5b6bf2245ca3479f7fd25d4746f3b6ef4b5a9f18b9179ff918c74695624cf17b704eef9542f3d5a0f3d91b2a33e888394ad79d59f6623a3b47a7d68f230a4d9ff875991fb528a954da579853013eae8b358535ba2f24899be32ca5a9c46d67bf6c9df5c1ab16e0ec2b0ba24703fa2afb2271e4f33c51608b951ada56b6e02aea0931f7db0456a68ea42bf6cf766cd6e0c3a93c746d6e2c352ea0ddca863f7e040b51bf921cba1bab4cae0b3ccc6f52c21a00a650cf24992bb5c2e7adef6a048eaf3492a8c1ac4863bf2b5a8cfb9095',
        'timeout' => 86400, // 24 hours
        'algorithm' => 'HS256'
    ],
];
?>