# OPNsense AI Security Wizard: Guía Práctica

**Borrador v0.1 — Daniel Freeman (danfreeus702)**
*Companion del plugin de código abierto: https://github.com/danfreeus702/os-ai-opensense*

> Este es un borrador de trabajo. Los capítulos 1–2 están completos; los demás
> están esbozados y se completarán a partir de las notas de pruebas del proyecto.

---

## Índice

1. Por qué existe un asistente así
2. Inicio rápido: instalar y aplicar por primera vez
3. Los tres niveles, explicados
4. DNS cifrado en OPNsense 26.7 (por qué DoT y no DoH)
5. El asistente de IA: documentación oficial y hardware
6. Leer las diferencias en lenguaje claro
7. Reversión: instantáneas y deshacer con un clic
8. Usar el asistente sin conexión
9. Verificación: cómo se probó el plugin
10. Solución de problemas y advertencias reales
11. Apéndice A — acciones de configd y rutas de archivos
12. Apéndice B — actualizar y desinstalar

---

## Capítulo 1 — Por qué existe un asistente así

Un firewall recién instalado es un lienzo en blanco. Esa es su fuerza y su
debilidad. Puede hacer cualquier cosa, pero quien está frente a la interfaz
debe decidir *qué*, *dónde* y *en qué orden*. En OPNsense eso significa navegar
más de 150 enlaces de menú entre firewall, DNS, VPN, servicios y sistema.
Casi todo el mundo acaba dejando el equipo a medio configurar o copiando ajustes
que no entiende.

El asistente de seguridad plantea otra pregunta: *¿cuánta protección quieres?*
y después genera un plan que puedes revisar. Sigues viendo cada cambio antes de
que ocurra, incluida la ruta exacta del menú donde podrías hacerlo a mano. El
asistente no es una caja negra: es un atajo con un registro de lo que hace.

### Reglas de diseño

Cuatro reglas dieron forma al proyecto:

1. **Nada cambia sin confirmación.** Cada acción que aplica cambios exige un
   `confirm=yes` explícito y la interfaz muestra primero las diferencias en
   lenguaje claro.
2. **Ningún secreto en config.xml.** La clave del modelo en la nube vive en un
   archivo solo para root, `/conf/aiopensense.key` (modo 600), para que no se
   copie en las copias de seguridad.
3. **Cada aplicación tiene reversión.** Antes del primer cambio se guarda una
   instantánea de configuración con marca de tiempo, y un único botón
   «Undo last change» la restaura.
4. **Solo modelos en la nube.** No se incluye ningún modelo local, así que el
   plugin funciona en equipos pequeños con poca memoria.

### Para quién es

- Usuarios domésticos que quieren una base segura en pocos minutos.
- Pequeñas oficinas sin ingeniero de red dedicado.
- Quien está aprendiendo OPNsense y quiere ver *cómo es una configuración
  correcta* antes de hacerla manualmente.

Si gestionas un despliegue complejo con varias WAN, BGP y una pila IDS completa,
este asistente no sustituye tu diseño: es un punto de partida limpio.

---

## Capítulo 2 — Inicio rápido: instalar y aplicar por primera vez

### Qué necesitas

- **OPNsense 26.7** o superior (probado en 26.7.9_3).
- Acceso a internet desde el firewall para descargar listas de bloqueo y usar
  el modelo en la nube.
- Una clave API de OpenRouter (principal) o NVIDIA NIM (respaldo).

### Instalar el paquete

Descarga `os-ai-opensense-0.1_1.pkg` desde la página de versiones de GitHub,
cópialo al firewall e instálalo como root:

```sh
pkg add /tmp/os-ai-opensense-0.1_1.pkg
```

Los hooks de instalación reinician configd y cargan la plantilla nueva.
Confírmalo con:

```sh
pkg info os-ai-opensense
```

### Añadir la clave API (solo root)

```sh
printf '%s\n' 'your-key-here' > /conf/aiopensense.key
chmod 600 /conf/aiopensense.key
```

Nunca pongas la clave en `config.xml`: ese archivo acaba en copias de seguridad
y paquetes de soporte.

### Abrir el asistente

En la interfaz web elige **OPNsense AI → Security Wizard**. Verás tres tarjetas.
Selecciona un nivel; se abre el panel del plan con una lista de pasos. Debajo
está la tabla **What will change**, con cuatro columnas:

- el cambio,
- su valor actual,
- el valor tendrá después de aplicar,
- la ruta exacta de la interfaz donde vive esa opción.

Lee esa tabla. Si algo no te convence, cierra la página: todavía no ha cambiado
nada.

### Aplicar

Marca las opciones (el nodo de salida de Tailscale solo está en el nivel 3) y
pulsa **Apply selected level**. El diálogo de confirmación avisa de que se
guardará automáticamente una instantánea para reversión. Al cabo de unos
segundos verás una marca verde por cada paso. El panel de resultado muestra
también la nota de la instantánea.

### Verificar

```sh
tail -20 /var/log/aiopensense-security.log
pfctl -sr | grep -E 'domain|https|domain-s'
```

Deberías ver las reglas de DNS y HTTPS del asistente entre las reglas activas
de pf, y una línea `OK` por cada acción en el registro de auditoría.

### Deshacer

Si quieres volver atrás, pulsa **Undo last change** en el panel de resultado.
El controlador restaura la última instantánea y recarga las reglas del firewall.
Comprueba el nivel aplicado:

```sh
grep -o '<security_level>[0-9]*</security_level>' /conf/config.xml
```

---

*Capítulos 3–12: esbozados arriba. Los siguientes en redactarse: «Los tres
niveles, explicados» y «DNS cifrado en OPNsense 26.7 (por qué DoT y no DoH)»,
a partir de NOTES.md y las constantes RESOLVERS/BLOCKLISTS del modelo.*
