<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Dashboard — Consumer Tissue Data System</title>
    <link type="image/x-icon" rel="icon" href="{{ asset('images/bilicon.ico') }}" />
    <style>
        body { font-family: system-ui, Arial, sans-serif; margin: 0; background: #f4f4f6; color: #222; }
        header { background: #060606; color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
        main { padding: 40px 24px; }
        .card { background: #fff; border: 1px solid #e5e5e8; border-radius: 8px; padding: 32px; max-width: 640px; }
        form { display: inline; }
        button { background: #060606; color: #fff; border: 0; border-radius: 5px; padding: 8px 18px; cursor: pointer; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <header>
        <strong>Consumer Tissue Data System</strong>
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button>Logout</button>
        </form>
    </header>
    <main>
        <div class="card">
            <h1>Welcome, {{ auth()->user()->username }}</h1>
            <p>You are logged in. Modules are being rebuilt page by page — this is a
               temporary landing page until your default page has been migrated.</p>
        </div>
    </main>
</body>
</html>
