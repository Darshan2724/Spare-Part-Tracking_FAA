# Windows 11 On-Premise Server Setup Guide

This guide details the exact steps to turn a standard Windows 11 Desktop into the company's dedicated **FAITH AUTOMATION** MES on-premise application server.

---

## 1. Required Software Prerequisites to Install on Windows 11

Before deploying the application, download and install the following software on the Windows 11 desktop:

| Software | Purpose | Download / Setup |
| :--- | :--- | :--- |
| **Windows 11 (64-bit Pro / Home)** | Host Operating System | Ensure latest Windows updates are installed. |
| **WSL 2 (Windows Subsystem for Linux)** | High-performance container engine | Run in Admin PowerShell: `wsl --install` |
| **Docker Desktop for Windows** | Runs Laravel, PostgreSQL, Nginx, Redis, Reverb, Adminer | [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Select WSL2 backend during setup) |
| **Git for Windows** | Source control synchronization | [Git SCM](https://git-scm.com/) |
| **Node.js (LTS v20+)** *(Optional on host)* | For local frontend builds or EAS CLI | [NodeJS](https://nodejs.org/) |
| **Web Browser (Chrome / Edge)** | Accessing Web Dashboard & Adminer | Pre-installed |

---

## 2. Server Network Configuration (Static LAN IP)

To ensure Android devices and desktop browsers can permanently communicate with the server, assign a fixed/static LAN IP:

1. **Option A (Recommended): DHCP IP Reservation**
   - Log into the company Wi-Fi router / switch.
   - Bind the Windows 11 Desktop's MAC address to a fixed IP (e.g. `192.168.9.200`).
2. **Option B: Windows Static IP Assignment**
   - Open **Windows Settings** > **Network & Internet** > **Ethernet / Wi-Fi** > **IP Assignment** > **Edit**.
   - Set to **Manual (IPv4)** and enter:
     - IP Address: `192.168.9.200`
     - Subnet Mask: `255.255.255.0`
     - Gateway: `192.168.9.1` (or your company gateway IP)
     - DNS: `8.8.8.8` / `1.1.1.1`

---

## 3. Windows Power & Sleep Settings

The server must never go to sleep during factory operations:
1. Open **Windows Settings** > **System** > **Power & Battery**.
2. Set **Screen and sleep**:
   - *When plugged in, turn off my screen after*: **Never** (or 30 mins)
   - *When plugged in, put my device to sleep after*: **Never** (CRITICAL)

---

## 4. Automatic Initialization Script

Open **PowerShell as Administrator** and navigate to the project directory:

```powershell
cd "C:\SpareTrack"
Set-ExecutionPolicy RemoteSigned -Scope Process
.\scripts\setup_server.ps1
```

This script will automatically:
1. Initialize the directory structure (`backups/`, `logs/`, `deploy/`).
2. Configure **Windows Firewall** inbound rules for ports:
   - `8080`: Web UI & REST API
   - `8085`: Laravel Reverb WebSockets
   - `8088`: Adminer Database Console
3. Copy `.env.production.example` to `.env` if missing.
4. Output the detected LAN IP address.
