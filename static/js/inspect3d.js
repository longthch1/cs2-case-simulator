/**
 * CS2 Weapon 3D Inspect Engine
 * Provides interactive WebGL 3D inspection, 360-degree rotation, lighting presets,
 * float wear patina mapping, StatTrak counter rendering, and inspect animations.
 */

class Weapon3DInspector {
    constructor() {
        this.container = null;
        this.canvas = null;
        this.scene = null;
        this.camera = null;
        this.renderer = null;
        this.weaponGroup = null;
        this.animationFrameId = null;
        this.isDragging = false;
        this.previousMousePosition = { x: 0, y: 0 };
        this.rotationVelocity = { x: 0, y: 0 };
        this.autoRotate = true;
        this.isFlipping = false;
        this.flipProgress = 0;
        this.lightMode = 'studio';
        this.currentSkin = null;

        // Lighting references
        this.keyLight = null;
        this.fillLight = null;
        this.rimLight = null;
    }

    init(containerId) {
        this.container = document.getElementById(containerId);
        if (!this.container) return;

        // Cleanup any previous instance
        this.destroy();

        const width = this.container.clientWidth || 700;
        const height = this.container.clientHeight || 450;

        // 1. Scene
        this.scene = new THREE.Scene();

        // 2. Camera
        this.camera = new THREE.PerspectiveCamera(40, width / height, 0.1, 1000);
        this.camera.position.set(0, 0, 8.5);

        // 3. WebGL Renderer
        this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
        this.renderer.setSize(width, height);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;

        this.canvas = this.renderer.domElement;
        this.canvas.className = "w-full h-full cursor-grab active:cursor-grabbing outline-none";
        this.container.appendChild(this.canvas);

        // 4. Lights
        this.setupLights();

        // 5. Weapon Master Group
        this.weaponGroup = new THREE.Group();
        this.scene.add(this.weaponGroup);

        // 6. Bind Mouse / Touch / Gesture events
        this.bindEvents();

        // 7. Start Animation Loop
        this.animate = this.animate.bind(this);
        this.animationFrameId = requestAnimationFrame(this.animate);

        // Handle Resize
        window.addEventListener('resize', this.onWindowResize.bind(this));
    }

    setupLights() {
        // Ambient Light
        this.ambientLight = new THREE.AmbientLight(0xffffff, 0.65);
        this.scene.add(this.ambientLight);

        // Key Directional Light
        this.keyLight = new THREE.DirectionalLight(0xffffff, 1.2);
        this.keyLight.position.set(5, 6, 8);
        this.scene.add(this.keyLight);

        // Fill / Rim Light (creates CS2 tactical sheen on edges)
        this.rimLight = new THREE.DirectionalLight(0xe58e26, 0.85); // CS2 Orange accent
        this.rimLight.position.set(-6, -3, -6);
        this.scene.add(this.rimLight);

        // Top Specular Point Light
        this.pointLight = new THREE.PointLight(0x88bbff, 0.9, 20);
        this.pointLight.position.set(0, 4, 3);
        this.scene.add(this.pointLight);
    }

    setLightMode(mode) {
        this.lightMode = mode;
        if (!this.keyLight || !this.rimLight) return;

        if (mode === 'studio') {
            this.ambientLight.color.setHex(0xffffff);
            this.ambientLight.intensity = 0.7;
            this.keyLight.color.setHex(0xffffff);
            this.rimLight.color.setHex(0xe58e26);
        } else if (mode === 'sunset') {
            this.ambientLight.color.setHex(0xffeedd);
            this.ambientLight.intensity = 0.6;
            this.keyLight.color.setHex(0xffaa44);
            this.rimLight.color.setHex(0xff4422);
        } else if (mode === 'cyberpunk') {
            this.ambientLight.color.setHex(0x221144);
            this.ambientLight.intensity = 0.8;
            this.keyLight.color.setHex(0x00ffff);
            this.rimLight.color.setHex(0xff00ff);
        } else if (mode === 'tactical') {
            this.ambientLight.color.setHex(0x334455);
            this.ambientLight.intensity = 0.5;
            this.keyLight.color.setHex(0x99ddff);
            this.rimLight.color.setHex(0x55ff77);
        }
    }

    loadSkin(skin) {
        this.currentSkin = skin;
        if (!this.weaponGroup) return;

        // Clear existing mesh
        while (this.weaponGroup.children.length > 0) {
            const obj = this.weaponGroup.children[0];
            if (obj.geometry) obj.geometry.dispose();
            if (obj.material) {
                if (Array.isArray(obj.material)) obj.material.forEach(m => m.dispose());
                else obj.material.dispose();
            }
            this.weaponGroup.remove(obj);
        }

        // Reset rotation and position
        this.weaponGroup.rotation.set(0.1, -0.4, 0.05);
        this.weaponGroup.position.set(0, 0, 0);
        this.rotationVelocity = { x: 0, y: 0 };

        // Determine weapon category
        const weaponName = (skin.weapon || '').toLowerCase();
        const isKnife = weaponName.includes('knife') || weaponName.includes('bayonet') || weaponName.includes('karambit') || weaponName.includes('daggers') || (skin.rarity_tier === 5);
        const isPistol = weaponName.includes('glock') || weaponName.includes('usp') || weaponName.includes('deagle') || weaponName.includes('p250') || weaponName.includes('berettas') || weaponName.includes('revolver') || weaponName.includes('five-seven') || weaponName.includes('cz75');
        const isSniper = weaponName.includes('awp') || weaponName.includes('ssg') || weaponName.includes('scar') || weaponName.includes('g3sg1');

        // Texture loader for authentic CS2 weapon artwork
        const textureLoader = new THREE.TextureLoader();
        textureLoader.crossOrigin = 'anonymous';

        const imgUrl = skin.image || '';

        textureLoader.load(
            imgUrl,
            (texture) => {
                texture.generateMipmaps = true;
                texture.minFilter = THREE.LinearMipmapLinearFilter;
                this.buildWeaponMesh(skin, texture, isKnife, isPistol, isSniper);
            },
            undefined,
            () => {
                // Fallback procedural canvas texture if remote image blocks CORS
                const fallbackTex = this.createFallbackCanvasTexture(skin);
                this.buildWeaponMesh(skin, fallbackTex, isKnife, isPistol, isSniper);
            }
        );
    }

    createFallbackCanvasTexture(skin) {
        const c = document.createElement('canvas');
        c.width = 512;
        c.height = 512;
        const ctx = c.getContext('2d');

        // Metallic gradient
        const grad = ctx.createLinearGradient(0, 0, 512, 512);
        grad.addColorStop(0, '#101520');
        grad.addColorStop(0.5, skin.rarity_color || '#e58e26');
        grad.addColorStop(1, '#080c14');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, 512, 512);

        // Camo pattern
        ctx.fillStyle = 'rgba(255, 255, 255, 0.15)';
        for (let i = 0; i < 20; i++) {
            ctx.beginPath();
            ctx.arc(Math.random() * 512, Math.random() * 512, Math.random() * 80 + 20, 0, Math.PI * 2);
            ctx.fill();
        }

        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 28px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(skin.name || 'CS2 WEAPON', 256, 256);

        const tex = new THREE.CanvasTexture(c);
        return tex;
    }

    buildWeaponMesh(skin, skinTexture, isKnife, isPistol, isSniper) {
        const floatVal = skin.float_value !== undefined ? skin.float_value : 0.05;
        // Float wear effect: Higher float = more roughness, darker diffuse, less gloss
        const roughness = Math.min(0.9, 0.25 + floatVal * 0.65);
        const metalness = Math.max(0.2, 0.85 - floatVal * 0.5);

        // Main Weapon Material (Standard PBR Shader)
        const weaponMaterial = new THREE.MeshStandardMaterial({
            map: skinTexture,
            roughness: roughness,
            metalness: metalness,
            side: THREE.DoubleSide
        });

        // Secondary Metal Material (matte tactical receiver / barrels)
        const darkMetalMaterial = new THREE.MeshStandardMaterial({
            color: 0x1b2230,
            roughness: 0.45,
            metalness: 0.9,
            side: THREE.DoubleSide
        });

        // Gold/Accent Material for knives/accents
        const accentMaterial = new THREE.MeshStandardMaterial({
            color: skin.rarity_tier === 5 ? 0xffcc33 : 0x334466,
            roughness: 0.2,
            metalness: 0.95
        });

        if (isKnife) {
            // Build 3D Knife (Blade + Handle + Guard)
            // 1. Blade Plane with Extrusion
            const bladeGeo = new THREE.BoxGeometry(3.6, 0.9, 0.08);
            const blade = new THREE.Mesh(bladeGeo, weaponMaterial);
            blade.position.set(0.6, 0.1, 0);

            // 2. Handle
            const handleGeo = new THREE.CylinderGeometry(0.22, 0.25, 2.0, 16);
            const handle = new THREE.Mesh(handleGeo, darkMetalMaterial);
            handle.rotation.z = Math.PI / 2;
            handle.position.set(-1.9, 0.05, 0);

            // 3. Guard & Ring
            const guardGeo = new THREE.TorusGeometry(0.35, 0.08, 12, 24);
            const guard = new THREE.Mesh(guardGeo, accentMaterial);
            guard.position.set(-1.0, 0.05, 0);
            guard.rotation.y = Math.PI / 2;

            this.weaponGroup.add(blade);
            this.weaponGroup.add(handle);
            this.weaponGroup.add(guard);

        } else if (isPistol) {
            // Build 3D Pistol (Slide + Grip + Barrel + Trigger)
            // 1. Slide
            const slideGeo = new THREE.BoxGeometry(3.2, 0.9, 0.55);
            const slide = new THREE.Mesh(slideGeo, weaponMaterial);
            slide.position.set(0.2, 0.5, 0);

            // 2. Barrel Tip
            const barrelGeo = new THREE.CylinderGeometry(0.18, 0.18, 0.8, 16);
            const barrel = new THREE.Mesh(barrelGeo, darkMetalMaterial);
            barrel.rotation.z = Math.PI / 2;
            barrel.position.set(1.9, 0.5, 0);

            // 3. Grip
            const gripGeo = new THREE.BoxGeometry(0.85, 1.8, 0.5);
            const grip = new THREE.Mesh(gripGeo, darkMetalMaterial);
            grip.position.set(-0.7, -0.6, 0);
            grip.rotation.z = -0.25; // Ergonomic slant

            // 4. Trigger Guard
            const triggerGuardGeo = new THREE.TorusGeometry(0.32, 0.06, 8, 16, Math.PI);
            const triggerGuard = new THREE.Mesh(triggerGuardGeo, darkMetalMaterial);
            triggerGuard.position.set(-0.1, -0.1, 0);
            triggerGuard.rotation.z = Math.PI / 2;

            this.weaponGroup.add(slide);
            this.weaponGroup.add(barrel);
            this.weaponGroup.add(grip);
            this.weaponGroup.add(triggerGuard);

        } else {
            // Build 3D Rifle / Sniper (Receiver + Barrel + Magazine + Stock + Optional Scope)
            // 1. Main Body / Receiver
            const bodyGeo = new THREE.BoxGeometry(4.4, 1.1, 0.6);
            const body = new THREE.Mesh(bodyGeo, weaponMaterial);
            body.position.set(0, 0.2, 0);

            // 2. Barrel
            const barrelGeo = new THREE.CylinderGeometry(0.14, 0.18, 3.8, 16);
            const barrel = new THREE.Mesh(barrelGeo, darkMetalMaterial);
            barrel.rotation.z = Math.PI / 2;
            barrel.position.set(3.8, 0.35, 0);

            // 3. Handguard / Shroud
            const shroudGeo = new THREE.BoxGeometry(2.4, 0.7, 0.65);
            const shroud = new THREE.Mesh(shroudGeo, weaponMaterial);
            shroud.position.set(2.8, 0.35, 0);

            // 4. Magazine
            const magGeo = new THREE.BoxGeometry(0.7, 1.9, 0.45);
            const mag = new THREE.Mesh(magGeo, darkMetalMaterial);
            mag.position.set(0.6, -1.0, 0);
            mag.rotation.z = 0.25;

            // 5. Stock
            const stockGeo = new THREE.BoxGeometry(2.2, 0.95, 0.45);
            const stock = new THREE.Mesh(stockGeo, darkMetalMaterial);
            stock.position.set(-3.1, 0.1, 0);

            // 6. Grip
            const gripGeo = new THREE.BoxGeometry(0.65, 1.4, 0.45);
            const grip = new THREE.Mesh(gripGeo, darkMetalMaterial);
            grip.position.set(-1.4, -0.8, 0);
            grip.rotation.z = -0.3;

            this.weaponGroup.add(body);
            this.weaponGroup.add(barrel);
            this.weaponGroup.add(shroud);
            this.weaponGroup.add(mag);
            this.weaponGroup.add(stock);
            this.weaponGroup.add(grip);

            // 7. Sniper Scope (if applicable)
            if (isSniper) {
                const scopeGeo = new THREE.CylinderGeometry(0.26, 0.28, 3.2, 16);
                const scope = new THREE.Mesh(scopeGeo, darkMetalMaterial);
                scope.rotation.z = Math.PI / 2;
                scope.position.set(0.2, 1.25, 0);

                const mountGeo = new THREE.BoxGeometry(1.6, 0.4, 0.3);
                const mount = new THREE.Mesh(mountGeo, darkMetalMaterial);
                mount.position.set(0.2, 0.9, 0);

                this.weaponGroup.add(scope);
                this.weaponGroup.add(mount);
            }
        }

        // Add 3D StatTrak LED Counter if StatTrak is true!
        if (skin.is_stattrak) {
            this.addStatTrakDisplay();
        }
    }

    addStatTrakDisplay() {
        // Orange Glowing Digital LED Counter
        const canvas = document.createElement('canvas');
        canvas.width = 256;
        canvas.height = 64;
        const ctx = canvas.getContext('2d');

        // Black bezel
        ctx.fillStyle = '#05070a';
        ctx.fillRect(0, 0, 256, 64);
        ctx.strokeStyle = '#222b3d';
        ctx.lineWidth = 4;
        ctx.strokeRect(2, 2, 252, 60);

        // LED digits
        ctx.fillStyle = '#ff6a00';
        ctx.shadowColor = '#ff6a00';
        ctx.shadowBlur = 10;
        ctx.font = 'bold 36px monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        const kills = Math.floor(Math.random() * 850 + 42);
        ctx.fillText(`★ ${String(kills).padStart(6, '0')}`, 128, 32);

        const ledTex = new THREE.CanvasTexture(canvas);
        const ledMat = new THREE.MeshBasicMaterial({ map: ledTex });
        const boxGeo = new THREE.BoxGeometry(0.9, 0.3, 0.12);
        const ledMesh = new THREE.Mesh(boxGeo, ledMat);
        ledMesh.position.set(-0.2, 0.5, 0.35); // Placed on side of weapon

        this.weaponGroup.add(ledMesh);
    }

    bindEvents() {
        if (!this.canvas) return;

        // Pointer Down
        const onDown = (clientX, clientY) => {
            this.isDragging = true;
            this.autoRotate = false;
            this.previousMousePosition = { x: clientX, y: clientY };
        };

        // Pointer Move
        const onMove = (clientX, clientY) => {
            if (!this.isDragging) return;
            const deltaX = clientX - this.previousMousePosition.x;
            const deltaY = clientY - this.previousMousePosition.y;

            this.rotationVelocity.x = deltaY * 0.005;
            this.rotationVelocity.y = deltaX * 0.005;

            if (this.weaponGroup) {
                this.weaponGroup.rotation.x += this.rotationVelocity.x;
                this.weaponGroup.rotation.y += this.rotationVelocity.y;
            }

            this.previousMousePosition = { x: clientX, y: clientY };
        };

        // Pointer Up
        const onUp = () => {
            this.isDragging = false;
        };

        // Mouse listeners
        this.canvas.addEventListener('mousedown', (e) => onDown(e.clientX, e.clientY));
        window.addEventListener('mousemove', (e) => onMove(e.clientX, e.clientY));
        window.addEventListener('mouseup', onUp);

        // Touch listeners (mobile support)
        this.canvas.addEventListener('touchstart', (e) => {
            if (e.touches.length === 1) onDown(e.touches[0].clientX, e.touches[0].clientY);
        }, { passive: true });
        window.addEventListener('touchmove', (e) => {
            if (e.touches.length === 1) onMove(e.touches[0].clientX, e.touches[0].clientY);
        }, { passive: true });
        window.addEventListener('touchend', onUp);

        // Mouse Wheel Zoom
        this.canvas.addEventListener('wheel', (e) => {
            e.preventDefault();
            if (!this.camera) return;
            const zoomDelta = e.deltaY * 0.005;
            this.camera.position.z = Math.max(4.0, Math.min(14.0, this.camera.position.z + zoomDelta));
        }, { passive: false });
    }

    triggerInspectFlip() {
        if (this.isFlipping) return;
        this.isFlipping = true;
        this.flipProgress = 0;
        this.autoRotate = false;

        // Play inspect sound if sound engine active
        if (window.soundEngine && typeof window.soundEngine.playTick === 'function') {
            window.soundEngine.playTick();
        }
    }

    resetView() {
        if (!this.weaponGroup || !this.camera) return;
        this.weaponGroup.rotation.set(0.1, -0.4, 0.05);
        this.weaponGroup.position.set(0, 0, 0);
        this.camera.position.set(0, 0, 8.5);
        this.rotationVelocity = { x: 0, y: 0 };
        this.autoRotate = true;
    }

    toggleAutoRotate() {
        this.autoRotate = !this.autoRotate;
        return this.autoRotate;
    }

    onWindowResize() {
        if (!this.container || !this.camera || !this.renderer) return;
        const width = this.container.clientWidth;
        const height = this.container.clientHeight;
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();
        this.renderer.setSize(width, height);
    }

    animate(time) {
        this.animationFrameId = requestAnimationFrame(this.animate);

        if (!this.weaponGroup) return;

        // 1. Inspect Fidget / Flip Animation
        if (this.isFlipping) {
            this.flipProgress += 0.035;
            // Complete 360 degree spin with vertical sine dip
            this.weaponGroup.rotation.z += 0.18;
            this.weaponGroup.rotation.y += 0.12;
            this.weaponGroup.position.y = Math.sin(this.flipProgress * Math.PI) * 0.45;

            if (this.flipProgress >= 1.0) {
                this.isFlipping = false;
                this.weaponGroup.position.y = 0;
            }
        } else {
            // 2. Idle Floating Bobbing (breathe effect like in CS2)
            const t = time * 0.0015;
            this.weaponGroup.position.y = Math.sin(t) * 0.08;

            // 3. Auto Rotation
            if (this.autoRotate && !this.isDragging) {
                this.weaponGroup.rotation.y += 0.006;
            }

            // 4. Inertial damping when mouse released
            if (!this.isDragging) {
                this.rotationVelocity.x *= 0.92;
                this.rotationVelocity.y *= 0.92;
                this.weaponGroup.rotation.x += this.rotationVelocity.x;
                this.weaponGroup.rotation.y += this.rotationVelocity.y;
            }
        }

        // Render Frame
        if (this.renderer && this.scene && this.camera) {
            this.renderer.render(this.scene, this.camera);
        }
    }

    destroy() {
        if (this.animationFrameId) {
            cancelAnimationFrame(this.animationFrameId);
            this.animationFrameId = null;
        }
        if (this.canvas && this.canvas.parentNode) {
            this.canvas.parentNode.removeChild(this.canvas);
        }
        if (this.renderer) {
            this.renderer.dispose();
            this.renderer = null;
        }
    }
}

// Global singleton instance
window.weaponInspector = new Weapon3DInspector();
