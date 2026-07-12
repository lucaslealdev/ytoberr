<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Ytoberr</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-950 flex items-center justify-center min-h-screen">
    <div class="bg-gray-900 p-8 rounded-lg shadow-md w-full max-w-md text-gray-100">
        <h1 class="text-2xl font-bold mb-6 text-center">Login</h1>
        
        @if ($errors->any())
            <div class="bg-red-900/50 text-red-200 p-4 rounded mb-4">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="/login" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-300">Email</label>
                <input type="email" name="email" class="w-full p-2 border border-gray-700 rounded mt-1 bg-gray-800 text-gray-100" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-300">Password</label>
                <input type="password" name="password" class="w-full p-2 border border-gray-700 rounded mt-1 bg-gray-800 text-gray-100" required>
            </div>
            <button type="submit" class="w-full bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Login</button>
        </form>
    </div>
</body>
</html>
