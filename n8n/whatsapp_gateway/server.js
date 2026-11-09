const { default: makeWASocket, useMultiFileAuthState, DisconnectReason } = require('@whiskeysockets/baileys');
const express = require('express');
const QRCode = require('qrcode');
const pino = require('pino');
const path = require('path');
const fs = require('fs');

const app = express();
app.use(express.json());

const PORT = process.env.PORT || 8088;
const AUTH_DIR = path.join(__dirname, 'auth_info_baileys');

let sock = null;
let latestQrDataUrl = null;
let isConnected = false;
let connectedPhone = null;

const logger = pino({ level: 'silent' });

async function connectToWhatsApp() {
    try {
        const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

        sock = makeWASocket({
            auth: state,
            printQRInTerminal: true,
            logger: logger,
            browser: ['Central Bomberos MS-03', 'Chrome', '1.0.0'],
        });

        sock.ev.on('creds.update', saveCreds);

        sock.ev.on('connection.update', async (update) => {
            const { connection, lastDisconnect, qr } = update;

            if (qr) {
                try {
                    latestQrDataUrl = await QRCode.toDataURL(qr, { width: 300, margin: 2 });
                    console.log('[WHATSAPP-GATEWAY] Nuevo código QR generado. Disponible en http://localhost:' + PORT);
                } catch (e) {
                    console.error('[WHATSAPP-GATEWAY] Error generando QR:', e);
                }
            }

            if (connection === 'close') {
                const shouldReconnect = lastDisconnect?.error?.output?.statusCode !== DisconnectReason.loggedOut;
                isConnected = false;
                connectedPhone = null;
                console.log('[WHATSAPP-GATEWAY] Conexión cerrada. Reconectando:', shouldReconnect);
                if (shouldReconnect) {
                    setTimeout(connectToWhatsApp, 3000);
                }
            } else if (connection === 'open') {
                isConnected = true;
                latestQrDataUrl = null;
                connectedPhone = sock.user?.id ? sock.user.id.split(':')[0] : 'Conectado';
                console.log('[WHATSAPP-GATEWAY] ✅ ¡Sesión de WhatsApp vinculada con éxito!');
                console.log('[WHATSAPP-GATEWAY] Número emisor de Bomberos:', connectedPhone);
            }
        });
    } catch (err) {
        console.error('[WHATSAPP-GATEWAY] Error inicializando Baileys:', err);
        setTimeout(connectToWhatsApp, 5000);
    }
}

// Endpoint para verificar si un número está registrado en WhatsApp
app.get('/check/:num', async (req, res) => {
    try {
        let clean = String(req.params.num).replace(/[^0-9]/g, '');
        if (clean.length === 8) clean = '591' + clean;
        const result = await sock.onWhatsApp(clean);
        return res.json({ clean, result, emisor: connectedPhone });
    } catch (e) {
        return res.status(500).json({ error: e.message });
    }
});

// Endpoint de estado para el frontend y n8n
app.get('/status', (req, res) => {
    res.json({
        conectado: isConnected,
        emisor: connectedPhone,
        tieneQr: Boolean(latestQrDataUrl),
    });
});

// Endpoint para obtener el QR en imagen
app.get('/qr-data', (req, res) => {
    res.json({
        conectado: isConnected,
        emisor: connectedPhone,
        qr: latestQrDataUrl,
    });
});

// Endpoint principal: n8n llama aquí para enviar el mensaje (soporta uno o múltiples destinatarios)
app.post('/enviar', async (req, res) => {
    try {
        const { numero, numeros, mensaje } = req.body;

        if ((!numero && !numeros) || !mensaje) {
            return res.status(400).json({
                exito: false,
                mensaje: 'Se requieren los campos "numero" (o lista "numeros") y "mensaje".',
            });
        }

        if (!sock || !isConnected) {
            return res.status(503).json({
                exito: false,
                mensaje: 'La sesión de WhatsApp de Bomberos no está conectada. Escanee el código QR en http://localhost:' + PORT,
            });
        }

        // Normalizar destinatarios: puede ser un array, o un string con comas/puntos y coma, o un solo número
        let targets = [];
        if (Array.isArray(numeros)) {
            targets = numeros;
        } else if (typeof numero === 'string' && (numero.includes(',') || numero.includes(';'))) {
            targets = numero.split(/[,;]/).map(n => n.trim()).filter(Boolean);
        } else if (numero) {
            targets = [numero];
        }

        const enviados = [];
        for (const item of targets) {
            let cleanNumber = String(item).replace(/[^0-9]/g, '');
            // Si tiene 8 dígitos (formato local Bolivia), anteponer código país 591
            if (cleanNumber.length === 8) {
                cleanNumber = '591' + cleanNumber;
            }

            if (cleanNumber.length >= 8) {
                try {
                    const check = await sock.onWhatsApp(cleanNumber);
                    console.log(`[WHATSAPP-GATEWAY] Verificación onWhatsApp para ${cleanNumber}:`, JSON.stringify(check));
                    
                    const jid = check?.[0]?.jid || `${cleanNumber}@s.whatsapp.net`;
                    console.log(`[WHATSAPP-GATEWAY] Despachando alerta oficial hacia: ${jid}`);
                    const sentResult = await sock.sendMessage(jid, { text: mensaje });
                    console.log(`[WHATSAPP-GATEWAY] Resultado sendMessage: status=${sentResult?.status}, keyId=${sentResult?.key?.id}`);
                    enviados.push(jid);
                } catch (sendErr) {
                    console.error(`[WHATSAPP-GATEWAY] Error enviando a ${cleanNumber}:`, sendErr);
                }
            }
        }

        return res.json({
            exito: true,
            destinatarios: enviados,
            totalEnviados: enviados.length,
            emisor: connectedPhone,
            mensaje: 'Alerta despachada correctamente por WhatsApp.',
            timestamp: new Date().toISOString(),
        });
    } catch (error) {
        console.error('[WHATSAPP-GATEWAY] Error al enviar mensaje:', error);
        return res.status(500).json({
            exito: false,
            error: error.message || 'Error interno al despachar mensaje.',
        });
    }
});

// Interfaz Web amigable para escanear el QR y probar
app.get('/', (req, res) => {
    res.send(`
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gateway WhatsApp - Central de Bomberos</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 32px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
        h1 {
            font-size: 20px;
            margin: 0 0 8px 0;
            color: #f59e0b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        p {
            font-size: 13px;
            color: #94a3b8;
            margin: 0 0 24px 0;
            line-height: 1.5;
        }
        .qr-box {
            background: #ffffff;
            padding: 16px;
            border-radius: 12px;
            display: inline-block;
            margin-bottom: 20px;
            min-height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qr-box img {
            display: block;
            max-width: 260px;
            height: auto;
        }
        .connected-box {
            background: #064e3b;
            border: 1px solid #10b981;
            color: #ecfdf5;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .connected-box h2 {
            font-size: 18px;
            margin: 0 0 8px 0;
            color: #34d399;
        }
        .steps {
            text-align: left;
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 16px 20px;
            font-size: 12px;
            color: #cbd5e1;
            margin-bottom: 20px;
        }
        .steps ol {
            margin: 0;
            padding-left: 20px;
        }
        .steps li {
            margin-bottom: 6px;
        }
        .btn-test {
            background: #f59e0b;
            color: #0f172a;
            border: none;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.2s;
            width: 100%;
        }
        .btn-test:hover {
            background: #d97706;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 16px;
        }
        .badge-warning {
            background: #78350f;
            color: #fde68a;
        }
        .badge-success {
            background: #064e3b;
            color: #a7f3d0;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>🚨 Central de Bomberos</h1>
        <p>Gateway Sidecar de WhatsApp para Automatización n8n</p>

        <div id="container-loading">
            <p>Iniciando servicio de enlace...</p>
        </div>

        <div id="container-qr" style="display:none;">
            <span class="badge badge-warning">Esperando Vinculación</span>
            <div class="steps">
                <ol>
                    <li>Abre WhatsApp en tu celular secundario (<strong>68809827</strong>).</li>
                    <li>Ve a <strong>Ajustes > Dispositivos vinculados</strong>.</li>
                    <li>Toca en <strong>Vincular un dispositivo</strong> y escanea este QR:</li>
                </ol>
            </div>
            <div class="qr-box">
                <img id="qr-image" src="" alt="Código QR WhatsApp" />
            </div>
            <p style="font-size:11px; color:#64748b;">El código se actualiza automáticamente cada 20 segundos.</p>
        </div>

        <div id="container-connected" style="display:none;">
            <div class="connected-box">
                <h2>✅ ¡Línea de Bomberos Conectada!</h2>
                <p style="color:#a7f3d0; margin:0;">
                    Emisor Oficial: <strong id="phone-display">+591 68809827</strong>
                </p>
            </div>
            <p style="font-size:12px; color:#94a3b8;">
                El sistema ya está listo. Cuando n8n o Inventario emitan una alerta, este número le enviará automáticamente el mensaje a tu WhatsApp <strong>60495035</strong>.
            </p>
            <button class="btn-test" onclick="probarEnvio()">Probar Alerta a mi WhatsApp (60495035)</button>
            <div id="test-result" style="margin-top:12px; font-size:12px;"></div>
        </div>
    </div>

    <script>
        async function checkStatus() {
            try {
                const res = await fetch('/qr-data');
                const data = await res.json();

                document.getElementById('container-loading').style.display = 'none';

                if (data.conectado) {
                    document.getElementById('container-qr').style.display = 'none';
                    document.getElementById('container-connected').style.display = 'block';
                    document.getElementById('phone-display').innerText = data.emisor || '+591 68809827';
                } else if (data.qr) {
                    document.getElementById('container-connected').style.display = 'none';
                    document.getElementById('container-qr').style.display = 'block';
                    document.getElementById('qr-image').src = data.qr;
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function probarEnvio() {
            const resultDiv = document.getElementById('test-result');
            resultDiv.innerHTML = '<span style="color:#f59e0b;">Despachando mensaje de prueba...</span>';
            try {
                const res = await fetch('/enviar', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        numero: '59160495035',
                        mensaje: '🚨 [PRUEBA OFICIAL CENTRAL DE BOMBEROS] Gateway de WhatsApp conectado exitosamente con la estación 68809827. Las alertas automatizadas de n8n están operativas.'
                    })
                });
                const json = await res.json();
                if (json.exito) {
                    resultDiv.innerHTML = '<span style="color:#34d399; font-weight:bold;">¡Mensaje enviado con éxito! Revisa tu WhatsApp (60495035) 📲</span>';
                } else {
                    resultDiv.innerHTML = '<span style="color:#f87171;">Fallo: ' + (json.mensaje || json.error) + '</span>';
                }
            } catch (err) {
                resultDiv.innerHTML = '<span style="color:#f87171;">Error al conectar con el servidor: ' + err.message + '</span>';
            }
        }

        setInterval(checkStatus, 2000);
        checkStatus();
    </script>
</body>
</html>
    `);
});

connectToWhatsApp();

app.listen(PORT, '0.0.0.0', () => {
    console.log(`[WHATSAPP-GATEWAY] Servidor activo en http://localhost:${PORT}`);
    console.log(`[WHATSAPP-GATEWAY] Abre esa URL en tu navegador para escanear el QR.`);
});
