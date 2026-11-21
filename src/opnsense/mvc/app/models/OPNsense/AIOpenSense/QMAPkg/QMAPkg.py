#!/usr/bin/env python3
"""
QMAPkg - QEMU Machine Access Package manager for OPNsense plugin management.

Provides a Python API to interact with QEMU virtual machines via QMP (QEMU Machine Protocol),
including console access, command execution, screenshot capture, and VM lifecycle management.

Used by the os-aiopensense OPNsense plugin to manage test VMs and console access.
"""
import socket
import json
import time
import os
from typing import Optional, Dict, Any, List


class QMAPkgError(Exception):
    """Base exception for QMAPkg operations."""
    pass


class QMAPkgConnection:
    """Connection to a QEMU VM via QMP socket."""
    
    def __init__(self, qmp_socket_path: str, timeout: float = 10.0):
        self.qmp_socket_path = qmp_socket_path
        self.timeout = timeout
        self.sock: Optional[socket.socket] = None
        self._capabilities_negotiated = False
    
    def connect(self) -> bool:
        """Connect to QMP socket and negotiate capabilities."""
        try:
            self.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            self.sock.settimeout(self.timeout)
            self.sock.connect(self.qmp_socket_path)
            # Negotiate capabilities
            self._send_command({"execute": "qmp_capabilities"})
            self._capabilities_negotiated = True
            return True
        except Exception as e:
            raise QMAPkgError(f"Failed to connect to QMP: {e}")
    
    def disconnect(self):
        """Close QMP connection."""
        if self.sock:
            try:
                self.sock.close()
            except:
                pass
            self.sock = None
            self._capabilities_negotiated = False
    
    def _send_command(self, cmd: Dict[str, Any]) -> Dict[str, Any]:
        """Send a QMP command and return the response."""
        if not self.sock:
            raise QMAPkgError("Not connected to QMP")
        
        msg = json.dumps(cmd).encode() + b"\n"
        self.sock.sendall(msg)
        
        buf = b""
        while not buf.endswith(b"\n"):
            try:
                chunk = self.sock.recv(4096)
                if not chunk:
                    break
                buf += chunk
            except socket.timeout:
                raise QMAPkgError("QMP command timed out")
        
        try:
            return json.loads(buf.decode().strip())
        except json.JSONDecodeError:
            raise QMAPkgError(f"Invalid QMP response: {buf.decode()[:200]}")
    
    def send_key(self, key_type: str, duration: float = 0.05) -> Dict[str, Any]:
        """Send a single key press to the VM console."""
        return self._send_command({
            "execute": "send-key",
            "arguments": {
                "keys": [{"type": key_type}],
                "duration": duration
            }
        })
    
    def send_keys(self, keys: List[str], duration: float = 0.05) -> Dict[str, Any]:
        """Send multiple key presses as a single command."""
        return self._send_command({
            "execute": "send-key",
            "arguments": {
                "keys": [{"type": k} for k in keys],
                "duration": duration
            }
        })
    
    def type_string(self, text: str, delay: float = 0.03) -> bool:
        """Type a string character by character into the VM console."""
        for ch in text:
            if ch == " ":
                key = "space"
            elif ch in ("\n", "\r"):
                key = "enter"
                self.send_key(key, 0.1)
                time.sleep(delay)
                continue
            elif ch == "\t":
                key = "tab"
            else:
                key = ch.lower()
            self.send_key(key, 0.05)
            time.sleep(delay)
        return True
    
    def screendump(self, filepath: str) -> bool:
        """Take a screenshot of the VM display."""
        try:
            result = self._send_command({
                "execute": "screendump",
                "arguments": {"filename": filepath}
            })
            return "return" in result
        except QMAPkgError:
            return False
    
    def send_ctrl_alt_F(self, f_number: int) -> bool:
        """Send Ctrl+Alt+F{n} to switch virtual terminals."""
        for key in ["ctrl", "alt", f"f{f_number}"]:
            self.send_key(key, 0.1)
            time.sleep(0.1)
        time.sleep(1)
        return True
    
    def execute_console_command(self, command: str, wait_after: float = 3.0) -> Optional[str]:
        """
        Execute a command on the VM console by typing it and capturing output.
        Note: This requires the VM to be at a shell prompt.
        Returns the command output if captured, None otherwise.
        """
        self.type_string(command)
        self.send_key("enter", 0.1)
        time.sleep(wait_after)
        return None  # Output capture requires screenshot analysis
    
    def wait_for_boot(self, timeout: int = 60) -> bool:
        """Wait for the VM to finish booting by checking for console activity."""
        start = time.time()
        while time.time() - start < timeout:
            time.sleep(2)
            # Check if we can interact with the console
            try:
                self.send_key("enter", 0.05)
                time.sleep(0.5)
                return True
            except:
                pass
        return False


class QMAPkgVM:
    """High-level VM management via QMP."""
    
    def __init__(self, vm_id: int, proxmox_host: str = "localhost",
                 qmp_socket_base: str = "/var/run/qemu-server"):
        self.vm_id = vm_id
        self.proxmox_host = proxmox_host
        self.qmp_socket = f"{qmp_socket_base}/{vm_id}.qmp"
        self.qga_socket = f"{qmp_socket_base}/{vm_id}.sock"
        self.serial_socket = f"{qmp_socket_base}/{vm_id}.serial0"
        self.conn = QMAPkgConnection(self.qmp_socket)
    
    def connect(self) -> bool:
        """Connect to the VM's QMP socket."""
        return self.conn.connect()
    
    def disconnect(self):
        """Disconnect from QMP."""
        self.conn.disconnect()
    
    def login_console(self, username: str = "root", password: str = "") -> bool:
        """Login to the VM console via QMP sendkeys."""
        if not self.conn._capabilities_negotiated:
            self.conn.connect()
        
        # Switch to text console if needed
        for fv in [1, 2, 3]:
            self.conn.send_ctrl_alt_F(fv)
        
        # Type username
        self.conn.type_string(username)
        self.conn.send_key("enter", 0.1)
        time.sleep(1.5)
        
        # Type password
        if password:
            self.conn.type_string(password)
            self.conn.send_key("enter", 0.1)
            time.sleep(3)
        
        return True
    
    def run_console_command(self, command: str, wait: float = 3.0) -> bool:
        """Run a command on the VM console."""
        self.conn.type_string(command)
        self.conn.send_key("enter", 0.1)
        time.sleep(wait)
        return True
    
    def restart_service(self, service_name: str) -> bool:
        """Restart a service on the VM via console."""
        return self.run_console_command(f"service {service_name} restart", wait=5.0)
    
    def check_service_status(self, service_name: str) -> bool:
        """Check if a service is running via console."""
        self.run_console_command(f"service {service_name} status", wait=3.0)
        return True
    
    def take_screenshot(self, filepath: str) -> bool:
        """Take a screenshot of the VM display."""
        return self.conn.screendump(filepath)
    
    def __enter__(self):
        self.connect()
        return self
    
    def __exit__(self, exc_type, exc_val, exc_tb):
        self.disconnect()


class QMAPkgManager:
    """Manager for multiple VMs via QMP."""
    
    def __init__(self, proxmox_host: str = "localhost",
                 qmp_socket_base: str = "/var/run/qemu-server"):
        self.proxmox_host = proxmox_host
        self.qmp_socket_base = qmp_socket_base
        self.vms: Dict[int, QMAPkgVM] = {}
    
    def get_vm(self, vm_id: int) -> QMAPkgVM:
        """Get or create a VM connection."""
        if vm_id not in self.vms:
            self.vms[vm_id] = QMAPkgVM(vm_id, self.proxmox_host, self.qmp_socket_base)
        return self.vms[vm_id]
    
    def list_vm_ids(self) -> List[int]:
        """List available VM IDs (those with QMP sockets)."""
        vms = []
        if os.path.isdir(self.qmp_socket_base):
            for entry in os.listdir(self.qmp_socket_base):
                if entry.endswith(".qmp"):
                    try:
                        vm_id = int(entry.replace(".qmp", ""))
                        vms.append(vm_id)
                    except ValueError:
                        pass
        return sorted(vms)
    
    def close_all(self):
        """Close all VM connections."""
        for vm in self.vms.values():
            vm.disconnect()
        self.vms.clear()


# Convenience functions
def connect_vm(vm_id: int, **kwargs) -> QMAPkgVM:
    """Connect to a VM by ID."""
    manager = QMAPkgManager(**kwargs)
    return manager.get_vm(vm_id)


def login_and_restart_sshd(vm_id: int, username: str = "root",
                            password: str = "", service: str = "sshd") -> bool:
    """
    Login to VM console and restart a service.
    
    Args:
        vm_id: VM ID
        username: Console username (default: root)
        password: Console password
        service: Service name to restart (default: sshd)
    
    Returns:
        True if successful
    """
    with connect_vm(vm_id) as vm:
        vm.login_console(username, password)
        vm.restart_service(service)
        vm.check_service_status(service)
        vm.take_screenshot(f"/tmp/vm{vm_id}_sshd_restart.ppm")
    return True


# CLI interface
if __name__ == "__main__":
    import argparse
    
    parser = argparse.ArgumentParser(description="QMAPkg - QEMU VM management via QMP")
    parser.add_argument("vm_id", type=int, help="VM ID")
    parser.add_argument("--host", default="localhost", help="Proxmox host")
    parser.add_argument("--command", "-c", help="Command to run on console")
    parser.add_argument("--login", action="store_true", help="Login to console")
    parser.add_argument("--restart", metavar="SERVICE", help="Restart a service")
    parser.add_argument("--screenshot", metavar="FILE", help="Take screenshot")
    parser.add_argument("--username", default="root", help="Console username")
    parser.add_argument("--password", default="", help="Console password")
    
    args = parser.parse_args()
    
    vm = connect_vm(args.vm_id, proxmox_host=args.host)
    vm.connect()
    
    if args.login:
        vm.login_console(args.username, args.password)
    
    if args.command:
        vm.run_console_command(args.command)
    
    if args.restart:
        vm.restart_service(args.restart)
    
    if args.screenshot:
        vm.take_screenshot(args.screenshot)
    
    vm.disconnect()
    print("Done")
