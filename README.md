# Integration-Project-Herkansing

## Requirements and Local Setup

This project is designed to run in a local on-premise environment. All required services run inside Docker containers on a Linux virtual machine. The host computer is only used to access the virtual machine through SSH, VS Code, or a web browser.

### Required software

To run this project, the following software is required:

- VirtualBox, VMware, or another virtualization tool
- Ubuntu Server 24.04 LTS, or another Linux server distribution
- Docker
- Docker Compose
- Git
- SSH client
- Web browser

### Recommended virtual machine configuration

The project can be run on a single virtual machine. The recommended minimum configuration is:

| Requirement | Recommended value |
| --- | --- |
| CPU | 2 cores or more |
| RAM | 6144 MB or more |
| Disk space | 40 GB or more |
| Network mode | Bridged Adapter |
| Operating system | Ubuntu Server 24.04 LTS, or similar Linux server distribution |

A bridged network adapter is recommended so that the virtual machine receives an IP address on the same network as the host computer. This makes it possible to access WordPress, Odoo, and RabbitMQ from the browser on the host computer.

### Tested environment

This project was developed and tested with the following setup:

| Setting | Value |
| --- | --- |
| Virtualization software | VirtualBox |
| Operating system | Ubuntu Server 24.04 LTS |
| CPU | 2 cores |
| RAM | 6144 MB |
| Network mode | Bridged Adapter |
| Docker Compose version | v5.2.0 |

### Accessing the virtual machine

After starting the virtual machine, find its IP address with:

```bash
ip a
```

Then connect to the VM from the host computer using SSH:

```bash
ssh <username>@<vm-ip>
```

Replace `<username>` with your Linux username and `<vm-ip>` with the IP address of your virtual machine.

### Running the project

Clone the repository on the virtual machine:

```bash
git clone https://github.com/Shems45/Integration-Project-Herkansing.git
cd <repository-folder>
```

Start the containers:

```bash
docker compose up -d
```

The project uses Docker containers for the required services, including Odoo, WordPress, and RabbitMQ. The host computer does not run these services directly. It only connects to the virtual machine.

### Example service URLs

Replace `<vm-ip>` with the IP address of your own virtual machine.

| Service | URL |
| --- | --- |
| WordPress | http://<vm-ip>:8080 |
| Odoo | http://<vm-ip>:8069 |
| RabbitMQ Management UI | http://<vm-ip>:15672 |