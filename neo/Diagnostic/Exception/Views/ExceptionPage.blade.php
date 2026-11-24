<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exception</title>
    <script src="../Assets/quas.min.js"></script>
</head>
<body>
    <template id="app">

        <pre w-code="{
            lang: 'php',
            code: controller,
            title: 'app/Http/Controllers/UserController.php',
            numbers: true }
        "></pre>

    </template>
</body>
<footer>
    <script src="../Assets/wex.min.js"></script>
    <script src="../Assets/wex-code.min.js"></script>
    <script>
        WexCode.install(Wex);

        $('#app')
            .state({})
            .mount();
    </script>
</footer>
</html>