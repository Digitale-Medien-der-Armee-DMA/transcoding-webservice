# FFmpeg and NVIDIA Smoke Runtime

Stand: 2026-06-24

Dieser PR fuehrt einen isolierten FFmpeg/NVIDIA-Smoke-Pfad ein, ohne die Laravel-/PHP-7.4-App-Runtime zu veraendern. Ziel ist ein wiederholbarer Nachweis, dass der Zielhost FFmpeg, NVIDIA Container Toolkit, NVENC und CUDA-Decode korrekt bereitstellt.

## Image-Strategie

Die Smoke-Runtime basiert auf:

- Basisimage: `nvidia/cuda:13.3.0-base-ubuntu24.04`
- Build-Argument: `CUDA_IMAGE`
- FFmpeg-Quelle: Ubuntu-24.04-Distribution-Paket innerhalb des gepinnten CUDA-Images
- Entrypoint: `docker/production/ffmpeg-smoke.sh`

Das ist bewusst kein finaler selbst gebauter FFmpeg-Source-Build. Fuer diese Modernisierungsstufe ist wichtig, den NVIDIA-Pfad reproduzierbar und separat vom alten Laravel/PHP-Image zu pruefen. Ein eigener signierter FFmpeg-Build kann spaeter eingefuehrt werden, wenn die App-Runtime selbst auf eine unterstuetzte PHP-Basis gehoben wird.

## Lokale Checks ohne GPU

Der CPU-Smoke-Test erzeugt ein synthetisches MP4 mit H.264/AAC und prueft das Ergebnis mit `ffprobe`:

```bash
make ffmpeg-cpu-smoke
```

Direkt per Compose:

```bash
docker compose --profile smoke -f compose.yaml run --rm ffmpeg-smoke-cpu
```

## NVIDIA-Host-Smoke

Voraussetzungen auf dem Zielhost:

- Ubuntu 24.04 LTS oder kompatibler Linux-Host
- NVIDIA-Treiber auf dem Host installiert
- NVIDIA Container Toolkit installiert und fuer Docker aktiviert
- Keine Host-Treiberinstallation im Container

Der GPU-Smoke-Test prueft:

- `nvidia-smi`
- `ffmpeg -hide_banner -hwaccels`
- Sichtbarkeit von `h264_nvenc`
- synthetisches H.264-Encoding mit NVENC
- CUDA-Decode-Pfad mit synthetischem H.264-Input

```bash
make ffmpeg-gpu-smoke
```

Direkt per Compose:

```bash
docker compose --profile gpu-smoke -f compose.yaml run --rm ffmpeg-smoke-gpu
```

## Konfiguration

Relevante `.env`-Werte:

```env
CUDA_IMAGE=nvidia/cuda:13.3.0-base-ubuntu24.04
FFMPEG_SMOKE_DURATION=2
FFMPEG_SMOKE_SIZE=1280x720
FFMPEG_SMOKE_RATE=30
NVIDIA_VISIBLE_DEVICES=all
NVIDIA_DRIVER_CAPABILITIES=compute,utility,video
GPU_DEVICE_COUNT=1
```

## Erwartetes Ergebnis

Erfolgreiche Ausfuehrung endet mit:

```text
CPU FFmpeg smoke passed: ...
GPU FFmpeg smoke passed: NVENC output=h264, CUDA decode path completed
```

Wenn `h264_nvenc` fehlt, ist die aktuelle Distribution-FFmpeg-Variante fuer den Produktionspfad nicht ausreichend. Dann ist die naechste Entscheidung ein eigener FFmpeg-Build oder ein gepflegtes, signiertes Image mit belegter NVENC/NVDEC-Unterstuetzung.

## CI-Grenze

GitHub Actions validiert Compose und Shell-Syntax. Der echte GPU-Smoke laeuft nicht in GitHub Actions, weil dort kein NVIDIA-GPU-Host garantiert ist.
