# Bundled third-party libraries

| File | Library | Version | License |
|------|---------|---------|---------|
| `qrcode-generator-1.4.4.js` | [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase | 1.4.4 | MIT (header in file) |
| `jsQR-1.4.0.js` | [jsQR](https://github.com/cozmo/jsQR) | 1.4.0 | Apache-2.0 (`jsQR-LICENSE.txt`) |

Bundled so Kiosk QR codes are generated locally (no payload is sent to a third-party
QR service) and scanning works without a public CDN.

## face-api.js

| Path | Library | Version | License |
|------|---------|---------|---------|
| `face-api/face-api-0.22.2.min.js` | [face-api.js](https://github.com/justadudewhohacks/face-api.js) | 0.22.2 | MIT (`face-api/LICENSE.txt`) |
| `face-api/models/*` | Pretrained weights from the face-api.js repo (`weights/`, commit a86f011) | — | MIT |

Only the three nets used by Face Sign In are bundled: `tiny_face_detector`,
`face_landmark_68`, `face_recognition`. Previously these were loaded from
jsDelivr and justadudewhohacks.github.io at runtime.
