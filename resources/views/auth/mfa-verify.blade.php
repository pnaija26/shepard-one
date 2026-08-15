<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <title>MFA Verification - ShepardOne</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    
    <!-- Styles -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            /*! tailwindcss v4.0.7 | MIT License | https://tailwindcss.com */
            body {
                font-family: 'Figtree', sans-serif;
                background-color: #fdfdfc;
                color: #1b1b18;
            }
        </style>
    @endif
</head>
<body class="bg-gray-50 dark:bg-gray-900 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-8 space-y-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Verify MFA</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    Enter the code from your authenticator app
                </p>
            </div>

            @if (session('error'))
                <div class="rounded-md bg-red-50 dark:bg-red-900/20 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.75 9.25a.75.75 0 000 1.5h2.5a.75.75 0 000-1.5h-2.5z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('mfa.verify') }}" class="space-y-6">
                @csrf
                
                <div>
                    <label for="totp_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Authenticator Code
                    </label>
                    <input 
                        id="totp_code" 
                        type="text" 
                        name="totp_code" 
                        required 
                        autocomplete="one-time-code"
                        placeholder="Enter 6-digit code from your authenticator app"
                        class="w-full rounded-lg border border-line px-4 py-2 focus:border-brand focus:ring-2 focus:ring-brand"
                    >
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Enter the 6-digit code shown in your authenticator app
                    </p>
                </div>

                <div>
                    <button type="submit" class="flex w-full justify-center rounded-md border border-transparent bg-brand px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-hover focus:outline-none focus:ring-2 focus:ring-brand focus:ring-offset-2">
                        Verify Code
                    </button>
                </div>
            </form>

            <div class="mt-4 text-center">
                <a href="{{ route('login') }}" class="text-sm text-brand hover:text-brand-hover">
                    &larr; Back to Login
                </a>
            </div>
        </div>
    </div>
</body>
</html>