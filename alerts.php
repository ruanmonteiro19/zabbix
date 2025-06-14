<?php
header('Content-Type: application/json; charset=utf-8');

$dsn = "pgsql:host=10.100.12.65;port=5432;dbname=zabbix;user=zabbix;password=Dqm50vnc";

// Mapear severidades para nome legível
function getSeverityName($severity) {
    $names = [
        0 => 'Not classified',
        1 => 'Information',
        2 => 'Warning',
        3 => 'Average',
        4 => 'High',
        5 => 'Disaster'
    ];
    return $names[$severity] ?? 'Unknown';
}

// Remove macros tipo {ITEM.NAME}, [SLA] etc.
function cleanMacros($text) {
    $text = preg_replace('/\{.*?\}/', '', $text);  // Remove {MACROS}
    $text = preg_replace('/\[.*?\]/', '', $text);  // Remove [MACROS]
    return trim(preg_replace('/\s+/', ' ', $text)); // Remove espaços extras
}

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "
        SELECT
            e.eventid,
            e.clock,
            t.triggerid,
            t.description AS raw_description,
            t.priority AS severity,
            h.host
        FROM
            events e
        JOIN
            triggers t ON e.objectid = t.triggerid
        JOIN
            functions f ON f.triggerid = t.triggerid
        JOIN
            items i ON i.itemid = f.itemid
        JOIN
            hosts h ON h.hostid = i.hostid
        WHERE
            e.source = 0
            AND e.object = 0
            AND e.value = 1
            AND t.priority >= 2 -- Apenas Warning ou superior
        GROUP BY
            e.eventid, e.clock, t.triggerid, t.description, t.priority, h.host
        ORDER BY e.clock DESC
        LIMIT 30;
    ";

    $stmt = $pdo->query($sql);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $alerts = [];
    $seen = [];

    foreach ($results as $alert) {
        $desc = cleanMacros($alert['raw_description']);

        $hash = md5($alert['host'] . $desc); // Evita duplicados
        if (!isset($seen[$hash])) {
            $alerts[] = [
                'host' => $alert['host'],
                'problem_name' => $desc,
                'severity' => getSeverityName((int)$alert['severity']),
                'clock' => $alert['clock'],
                'problemid' => $alert['eventid']
            ];
            $seen[$hash] = true;
        }
    }

    echo json_encode($alerts);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro SQL: ' . $e->getMessage()]);
}
