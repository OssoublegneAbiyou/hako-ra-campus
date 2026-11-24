<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1e1848">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    {{-- Cœur commun du stockage hors connexion : public, sans donnée, partagé avec la page hors ligne. Chargé avant l'application.
         Le paramètre de version le fait toujours venir du réseau en ligne : l'application n'utilise jamais une copie périmée de la coque. --}}
    <script src="/offline-core.js?v={{ filemtime(public_path('offline-core.js')) }}" defer></script>
    @viteReactRefresh
    @vite('resources/js/app.tsx')
    {{-- Directives et non composants <x-inertia::*> : ces derniers écrivent les données de chaque page dans le cache de vues. --}}
    @inertiaHead
</head>
<body>
    {{-- Données de la page dans un bloc JSON non exécutable. Les chevrons et l'esperluette sont échappés (JSON_HEX_TAG, JSON_HEX_AMP) :
         sans cela, une donnée contenant « <!--<script> » ferait avaler le reste du document par ce bloc et l'écran resterait vide
         pour toute personne qui la reçoit. La directive @inertia du paquet n'échappe pas ces caractères. --}}
    <script data-page="app" type="application/json">{!! json_encode($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script><div id="app"></div>
    <noscript>Campus nécessite JavaScript pour afficher son interface. Activez-le puis rechargez la page.</noscript>
</body>
</html>
