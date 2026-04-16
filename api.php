<?php
/**
 * API PHP - Intégration Supabase
 * Middleware entre le client Leaflet et la base de données Supabase
 * Convertit les réponses en GeoJSON et gère les tables PostGIS
 */

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Gestion des requêtes OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration Supabase
define('SUPABASE_URL', 'https://swgpmkfgoikesbwewnhd.supabase.co');
define('SUPABASE_API_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InN3Z3Bta2Znb2lrZXNid2V3bmhkIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzU2ODkxNzgsImV4cCI6MjA5MTI2NTE3OH0.MBYsj6Y7txIgn3tgX3KxLzhfSI0wtxrF6n5piNOpMKw');

/**
 * Requête HTTP authentifiée vers Supabase REST API
 */
function callSupabase($method, $endpoint, $data = null) {
    $url = SUPABASE_URL . '/rest/v1' . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $headers = [
        'apikey: ' . SUPABASE_API_KEY,
        'Authorization: Bearer ' . SUPABASE_API_KEY,
        'Content-Type: application/json'
    ];
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($data && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'status' => $httpCode,
        'data' => json_decode($response, true),
        'raw' => $response,
        'error' => $error
    ];
}

/**
 * Nettoyer les données pour éviter les valeurs Inf et NaN
 * qui ne peuvent pas être encodées en JSON
 */
function cleanForJSON($value) {
    if (is_array($value)) {
        return array_map('cleanForJSON', $value);
    } elseif (is_float($value)) {
        if (is_infinite($value) || is_nan($value)) {
            return null;
        }
    }
    return $value;
}

/**
 * Normaliser les propriétés des équipements
 * Extrait le nom et la surface avec priorité
 */
function normalizeEquipementProperties($row) {
    $normalized = $row;
    
    // Extraire le nom de la colonne "Text" (majuscule - c'est la vraie colonne!)
    $nom = null;
    if (isset($row['Text']) && !empty($row['Text'])) {
        $nom = $row['Text'];
    } else {
        // Fallback sur autres colonnes
        foreach (['texte', 'nom_equipement', 'nom', 'name', 'type_equipement'] as $field) {
            if (isset($row[$field]) && !empty($row[$field])) {
                $nom = $row[$field];
                break;
            }
        }
    }
    if ($nom) {
        $normalized['nom_equipement'] = $nom;
    }
    
    // Extraire la surface (chercher dans plusieurs colonnes possibles)
    $surface = null;
    foreach (['surface_m2', 'surface', 'superficie', 'area'] as $field) {
        if (isset($row[$field]) && is_numeric($row[$field]) && $row[$field] > 0) {
            $surface = (float)$row[$field];
            break;
        }
    }
    if ($surface) {
        $normalized['surface'] = $surface;
    }
    
    return $normalized;
}

// Extraire les paramètres
$action = $_GET['action'] ?? null;
$layer = $_GET['layer'] ?? null;

// DEBUG: Log les paramètres reçus
error_log("=== API.PHP DEBUG ===");
error_log("ACTION: " . ($action ?? "NULL"));
error_log("LAYER: " . ($layer ?? "NULL"));
error_log("METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("URL: " . $_SERVER['REQUEST_URI']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    error_log("BODY LENGTH: " . strlen($body));
    error_log("BODY: " . substr($body, 0, 200));
}
error_log("==================");

/**
 * Fonction pour générer les routes principales à partir des îlots
 */
function generateRoutesMain() {
    // Récupérer les îlots
    $result = callSupabase('GET', '/Ilot');
    
    if ($result['status'] !== 200 || !is_array($result['data'])) {
        return ['type' => 'FeatureCollection', 'features' => []];
    }
    
    $features = [];
    
    // Les contours des îlots deviennent les routes principales
    foreach ($result['data'] as $idx => $ilot) {
        if (!isset($ilot['geom']) && !isset($ilot['geometry'])) {
            continue;
        }
        
        $geomColumn = isset($ilot['geom']) ? 'geom' : 'geometry';
        $geometry = is_string($ilot[$geomColumn]) 
            ? json_decode($ilot[$geomColumn], true)
            : $ilot[$geomColumn];
        
        if ($geometry && isset($geometry['coordinates'])) {
            $geometry = cleanForJSON($geometry);
            
            if ($geometry && $geometry['coordinates']) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => $geometry,
                    'properties' => [
                        'id' => $ilot['id'] ?? $idx,
                        'type' => 'Route principale',
                        'longueur' => 'N/A',
                        'source' => 'Ilot'
                    ]
                ];
            }
        }
    }
    
    return [
        'type' => 'FeatureCollection',
        'features' => $features,
        'table' => 'RoutePrincipale',
        'count' => count($features)
    ];
}

/**
 * Fonction pour générer les routes secondaires à partir des parcelles
 */
function generateRoutesSecondary() {
    // Récupérer les parcelles
    $result = callSupabase('GET', '/Parcelle');
    
    if ($result['status'] !== 200 || !is_array($result['data'])) {
        return ['type' => 'FeatureCollection', 'features' => []];
    }
    
    $features = [];
    $count = 0;
    
    // Les contours des parcelles deviennent les routes secondaires
    foreach ($result['data'] as $idx => $parcelle) {
        // Limiter à 30 parcelles pour ne pas surcharger
        if ($count >= 30) break;
        
        if (!isset($parcelle['geometry']) && !isset($parcelle['geom'])) {
            continue;
        }
        
        $geomColumn = isset($parcelle['geometry']) ? 'geometry' : 'geom';
        $geometry = is_string($parcelle[$geomColumn]) 
            ? json_decode($parcelle[$geomColumn], true)
            : $parcelle[$geomColumn];
        
        if ($geometry && isset($geometry['coordinates'])) {
            $geometry = cleanForJSON($geometry);
            
            if ($geometry && $geometry['coordinates']) {
                $features[] = [
                    'type' => 'Feature',
                    'geometry' => $geometry,
                    'properties' => [
                        'id' => $parcelle['id'] ?? $parcelle['num_parcelle'] ?? $idx,
                        'type' => 'Route secondaire',
                        'longueur' => 'N/A',
                        'source' => 'Parcelle'
                    ]
                ];
                $count++;
            }
        }
    }
    
    return [
        'type' => 'FeatureCollection',
        'features' => $features,
        'table' => 'RouteSecondaire',
        'count' => count($features)
    ];
}

try {
    // ═══════════════════════════════════════════════════════════════════════════════
    // ACTIONS SPÉCIALES (avant les layers)
    // ═══════════════════════════════════════════════════════════════════════════════
    
    // TEST - Vérifier la connexion Supabase
    if ($action === 'test') {
        $result = callSupabase('GET', '/Parcelle?limit=1');
        
        if ($result['status'] === 200) {
            echo json_encode(cleanForJSON([
                'status' => 'connected',
                'message' => 'Connexion Supabase OK',
                'data_received' => count($result['data'] ?? [])
            ]));
            exit();
        } else {
            echo json_encode(cleanForJSON([
                'status' => 'error',
                'message' => 'Erreur connexion: ' . $result['status'],
                'supabase_error' => $result['data']
            ]));
            exit();
        }
    }
    
    // DEBUG - Voir la structure des données d'une table
    elseif ($action === 'debug') {
        $table = $_GET['table'] ?? 'Parcelle';
        
        $result = callSupabase('GET', '/' . $table . '?limit=1');
        
        echo json_encode(cleanForJSON([
            'table' => $table,
            'http_status' => $result['status'],
            'first_row' => $result['data'][0] ?? null,
            'columns' => !empty($result['data']) ? array_keys($result['data'][0]) : [],
            'error' => $result['error'] ?: null,
            'supabase_response' => $result['data']
        ]));
        exit();
    }
    
    // VÉRIFIER SI UNE PARCELLE EST VENDUE
    elseif ($action === 'checkVente') {
        $feature_id = $_GET['feature_id'] ?? null;
        $layer = $_GET['layer'] ?? 'parcelle';
        
        if (!$feature_id) {
            echo json_encode(['error' => 'feature_id manquant']);
            exit();
        }
        
        // Vérifier si la parcelle existe dans la table vente
        $result = callSupabase('GET', '/vente?feature_id=eq.' . $feature_id . '&layer=eq.' . $layer);
        
        $estVendue = ($result['status'] === 200 && count($result['data']) > 0);
        
        echo json_encode(cleanForJSON([
            'feature_id' => $feature_id,
            'estVendue' => $estVendue,
            'vente' => $estVendue ? $result['data'][0] : null
        ]));
        exit();
    }
    
    // ENREGISTRER UNE VENTE
    elseif ($action === 'enregistrerVente') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Méthode non autorisée, utilisez POST']);
            exit();
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        error_log("DEBUG enregistrerVente: " . json_encode($input));
        
        $layer = $input['layer'] ?? 'parcelle';
        $feature_id = $input['feature_id'] ?? null;
        $buyer_nom = $input['buyer_nom'] ?? '';
        $buyer_prenom = $input['buyer_prenom'] ?? '';
        $carte_identite = $input['carte_identite'] ?? '';
        $adresse = $input['adresse'] ?? '';
        $telephone = $input['telephone'] ?? '';
        $profession = $input['profession'] ?? '';
        $price = $input['price'] ?? null;
        $photo = $input['photo'] ?? null;
        
        if (!$feature_id) {
            http_response_code(400);
            echo json_encode(['error' => 'feature_id manquant']);
            exit();
        }
        
        // Vérifier que la parcelle n'est pas déjà vendue
        $checkResult = callSupabase('GET', '/vente?feature_id=eq.' . $feature_id . '&layer=eq.' . $layer);
        if ($checkResult['status'] === 200 && count($checkResult['data']) > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Cette parcelle est déjà vendue']);
            exit();
        }
        
        // Insérer la vente
        $venteData = [
            'layer' => $layer,
            'feature_id' => (int)$feature_id,
            'buyer_nom' => $buyer_nom,
            'buyer_prenom' => $buyer_prenom,
            'carte_identite' => $carte_identite,
            'adresse' => $adresse,
            'telephone' => $telephone,
            'profession' => $profession,
            'price' => $price ? (float)$price : null
        ];
        
        // Ajouter la photo si présente (base64 -> elle sera stockée en bytea)
        if ($photo) {
            $venteData['photo'] = $photo;
        }
        
        error_log("DEBUG: envoi à Supabase: " . json_encode($venteData));
        
        $result = callSupabase('POST', '/vente', $venteData);
        
        error_log("DEBUG: réponse Supabase status=" . $result['status']);
        
        if ($result['status'] === 201 || $result['status'] === 200) {
            echo json_encode(cleanForJSON([
                'success' => true,
                'message' => 'Vente enregistrée',
                'vente' => $result['data']
            ]));
        } else {
            http_response_code(500);
            echo json_encode(cleanForJSON([
                'error' => 'Erreur lors de l\'enregistrement',
                'status' => $result['status'],
                'details' => $result['data']
            ]));
        }
        exit();
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // GET LAYER - Récupérer GeoJSON pour une couche spécifique
    // ═══════════════════════════════════════════════════════════════════════════════
    elseif ($layer !== null && $layer !== 'stats') {
        // Mapping des noms de couches aux tables Supabase (case-sensitive)
        $tableMap = [
            'parcelle' => 'Parcelle',
            'ilot' => 'Ilot',
            'equipement' => 'Equipement',
            'route_principale' => 'Route_principal',
            'route_secondaire' => 'Route_secondaire',
            'cotation' => 'Cotation',
            'lotissement' => 'zone',
            'zone' => 'zone'
        ];
        
        $supabaseTable = $tableMap[$layer] ?? $layer;
        
        // Récupérer les données de la table
        $result = callSupabase('GET', '/' . $supabaseTable);
        
        // Construire les features GeoJSON
        $features = [];
        
        if ($result['status'] === 200 && is_array($result['data'])) {
            foreach ($result['data'] as $row) {
                // Normaliser les équipements
                if ($layer === 'equipement') {
                    $row = normalizeEquipementProperties($row);
                }
                
                // Chercher la colonne de géométrie
                $geometry = null;
                foreach (['geometry', 'geom', 'geo'] as $key) {
                    if (isset($row[$key])) {
                        $geometry = is_string($row[$key]) ? json_decode($row[$key], true) : $row[$key];
                        break;
                    }
                }
                
                // Si une géométrie est trouvée, créer un Feature
                if ($geometry) {
                    $properties = array_diff_key($row, array_flip(['geometry', 'geom', 'geo']));
                    $features[] = [
                        'type' => 'Feature',
                        'geometry' => $geometry,
                        'properties' => $properties
                    ];
                }
            }
        }
        
        // Retourner le GeoJSON FeatureCollection
        http_response_code(200);
        echo json_encode(cleanForJSON([
            'type' => 'FeatureCollection',
            'features' => $features,
            'table' => $supabaseTable,
            'count' => count($features)
        ]));
        exit();
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // GET STATS - Récupérer les statistiques
    // ═══════════════════════════════════════════════════════════════════════════════
    elseif ($layer === 'stats') {
        $stats = [
            'nb_parcelles' => 0,
            'nb_parcelles_vendus' => 0,
            'nb_parcelles_disponibles' => 0,
            'nb_ilots' => 0,
            'nb_equipements' => 0,
            'nb_routes_p' => 0,
            'nb_routes_s' => 0,
            'surface_zone' => null,
            'surface_parcelle' => null,
            'surface_equipement' => null,
            'longueur_routes' => null
        ];
        
        // Compter les lignes de chaque table
        $tables = [
            'Parcelle' => 'nb_parcelles',
            'Ilot' => 'nb_ilots',
            'Equipement' => 'nb_equipements',
            'RoutePrincipale' => 'nb_routes_p',
            'RouteSecondaire' => 'nb_routes_s'
        ];
        
        foreach ($tables as $t => $key) {
            $r = callSupabase('GET', '/' . $t);
            if ($r['status'] === 200 && is_array($r['data'])) {
                $stats[$key] = count($r['data']);
            }
        }
        
        // Compter les parcelles vendues
        $r_ventes = callSupabase('GET', '/vente?layer=eq.parcelle');
        if ($r_ventes['status'] === 200 && is_array($r_ventes['data'])) {
            $stats['nb_parcelles_vendus'] = count($r_ventes['data']);
        }
        
        // Calculer les parcelles disponibles
        $stats['nb_parcelles_disponibles'] = max(0, $stats['nb_parcelles'] - $stats['nb_parcelles_vendus']);
        
        // Récupérer les surfaces des Parcelles
        $r = callSupabase('GET', '/Parcelle');
        if ($r['status'] === 200 && is_array($r['data'])) {
            $total_surface = 0;
            foreach ($r['data'] as $parcel) {
                if (isset($parcel['surface'])) {
                    $total_surface += (float)$parcel['surface'];
                }
            }
            if ($total_surface > 0) {
                $stats['surface_parcelle'] = $total_surface;
            }
        }
        
        // Récupérer les surfaces des Équipements
        $r = callSupabase('GET', '/Equipement');
        if ($r['status'] === 200 && is_array($r['data'])) {
            $total_surface = 0;
            foreach ($r['data'] as $equip) {
                // Chercher la surface dans plusieurs colonnes possibles
                $surface = null;
                foreach (['surface_m2', 'surface', 'superficie', 'area'] as $field) {
                    if (isset($equip[$field]) && is_numeric($equip[$field])) {
                        $surface = (float)$equip[$field];
                        break;
                    }
                }
                if ($surface && $surface > 0) {
                    $total_surface += $surface;
                }
            }
            if ($total_surface > 0) {
                $stats['surface_equipement'] = $total_surface;
            }
        }
        
        // Récupérer la surface de la Zone
        $r = callSupabase('GET', '/zone');
        if ($r['status'] === 200 && is_array($r['data']) && !empty($r['data'])) {
            if (isset($r['data'][0]['surface'])) {
                $stats['surface_zone'] = (float)$r['data'][0]['surface'];
            }
        }
        
        // Récupérer les longueurs des Voiries
        $r_routes_p = callSupabase('GET', '/RoutePrincipale');
        $r_routes_s = callSupabase('GET', '/RouteSecondaire');
        
        $total_longueur = 0;
        if ($r_routes_p['status'] === 200 && is_array($r_routes_p['data'])) {
            foreach ($r_routes_p['data'] as $route) {
                if (isset($route['longueur'])) {
                    $total_longueur += (float)$route['longueur'];
                }
            }
        }
        if ($r_routes_s['status'] === 200 && is_array($r_routes_s['data'])) {
            foreach ($r_routes_s['data'] as $route) {
                if (isset($route['longueur'])) {
                    $total_longueur += (float)$route['longueur'];
                }
            }
        }
        if ($total_longueur > 0) {
            $stats['longueur_routes'] = $total_longueur;
        }
        
        echo json_encode(cleanForJSON($stats));
        exit();
    }
    
    // ═══════════════════════════════════════════════════════════════════════════════
    // ACTION NON RECONNUE
    // ═══════════════════════════════════════════════════════════════════════════════
    else {
        echo json_encode(cleanForJSON([
            'error' => 'Action non reconnue',
            'action_received' => $action,
            'method' => $_SERVER['REQUEST_METHOD'],
            'all_params' => $_GET
        ]));
        exit();
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(cleanForJSON(['error' => $e->getMessage()]));
    exit();
}
?>
