/*
 * La fenetre d'hyperespace — ressource de Codex (`wormhole-entry.js`, revue 114), reprise telle
 * quelle dans le paquet du jeu.
 *
 * ## Ce que c'est, et ce que ce n'est pas
 *
 * Un canvas transparent qui joue **une** ouverture, un tourbillon bleu, une fermeture — 4 200 ms —
 * puis s'arrete et s'efface. Aucune image, aucun vaisseau : la representation et le deplacement des
 * vraies flottes restent a la carte (`galaxy-tactical.js`), qui decide **quand** jouer la fenetre.
 * La fin de l'animation ne provoque jamais une arrivee : c'est un effet, pas un fait.
 *
 * Repere logique 360 x 220, centre de l'ouverture (180, 110), resolution interne 720 x 440 ; le
 * canvas porte une marge transparente, le vortex n'en occupe pas toute la largeur. Sous
 * `prefers-reduced-motion`, un vortex fixe en fondu, sans tourbillon ni changement d'echelle.
 *
 * `playOGameXWormhole(canvas)` rend `{ cancel(), previewAt(ms) }`. `cancel()` arrete et efface :
 * a appeler avant de retirer le canvas, de changer de systeme ou de rejouer dessus. `previewAt(ms)`
 * dessine une image donnee — la carte s'en sert pour jouer la fenetre **a l'envers** a la sortie de
 * l'hyperespace, ce qui n'est pas un mecanisme metier.
 *
 * Ce fichier est concatene avant `galaxy-tactical.js` (voir `vite.config.js`) : il ne lit rien de la
 * page et n'expose qu'une fonction globale.
 */
window.playOGameXWormhole = function (canvas) {
    const c = canvas.getContext('2d');
    const D = 4200;
    const reduce = matchMedia('(prefers-reduced-motion:reduce)').matches;
    canvas.width = 720;
    canvas.height = 440;
    c.scale(2, 2);
    let raf;
    let start;
    let stop = false;

    const smooth = (x) => {
        x = Math.max(0, Math.min(1, x));

        return x * x * (3 - 2 * x);
    };

    function draw(ms) {
        const t = Math.min(ms / D, 1);
        const o = smooth(t / 0.22) * (1 - smooth((t - 0.79) / 0.20));
        c.clearRect(0, 0, 360, 220);

        if (o > 0) {
            if (reduce) {
                ms = 0;
            }

            c.save();
            c.translate(180, 110);
            c.globalAlpha = reduce ? o : 1;
            c.scale(0.56 * (reduce ? 1 : o), reduce ? 1 : o);
            let g = c.createRadialGradient(0, 0, 0, 0, 0, 98);
            g.addColorStop(0, '#020719');
            g.addColorStop(0.64, '#06396a');
            g.addColorStop(0.74, '#158cdb88');
            g.addColorStop(1, '#168aff00');
            c.fillStyle = g;
            c.beginPath();
            c.arc(0, 0, 98, 0, 7);
            c.fill();

            c.save();
            c.beginPath();
            c.arc(0, 0, 69, 0, 7);
            c.clip();
            c.fillStyle = '#020b21';
            c.fillRect(-70, -70, 140, 140);
            c.globalCompositeOperation = 'screen';

            for (let j = 0; j < 48; j++) {
                c.beginPath();

                for (let k = 0; k < 66; k++) {
                    const r = 5 + k;
                    const a = j * 2.39996 + r * 0.049 + ms * 0.0012 + 0.1 * Math.sin(r * 0.18 + ms * 0.002 + j);
                    const px = Math.cos(a) * r;
                    const py = Math.sin(a) * r;

                    if (k) {
                        c.lineTo(px, py);
                    } else {
                        c.moveTo(px, py);
                    }
                }

                c.strokeStyle = `rgba(65,${140 + (j % 5) * 18},255,${0.14 + (j % 4) * 0.04})`;
                c.lineWidth = 0.5 + (j % 3) * 0.4;
                c.stroke();
            }

            c.restore();

            g = c.createRadialGradient(-4, 0, 0, 0, 0, 43);
            g.addColorStop(0, '#00030aff');
            g.addColorStop(0.3, '#000919ee');
            g.addColorStop(1, '#001a4800');
            c.fillStyle = g;
            c.beginPath();
            c.arc(0, 0, 43, 0, 7);
            c.fill();

            for (let j = 0; j < 4; j++) {
                c.beginPath();

                for (let k = 0; k <= 180; k++) {
                    const a = (k * Math.PI) / 90;
                    const r = 69 + j * 0.8 + Math.sin(a * 11 + ms * 0.004 + j) * 1.3 + Math.sin(a * 23 - ms * 0.002) * 0.7;

                    if (k) {
                        c.lineTo(Math.cos(a) * r, Math.sin(a) * r);
                    } else {
                        c.moveTo(Math.cos(a) * r, Math.sin(a) * r);
                    }
                }

                c.strokeStyle = j ? '#4ccfff88' : '#d3faff';
                c.lineWidth = j ? 0.8 : 1.5;
                c.shadowColor = '#168fff';
                c.shadowBlur = 9;
                c.stroke();
            }

            c.restore();
        }
    }

    function tick(now) {
        if (stop) {
            return;
        }

        if (start === undefined) {
            start = now;
        }

        draw(now - start);

        if (now - start < D) {
            raf = requestAnimationFrame(tick);
        }
    }

    raf = requestAnimationFrame(tick);

    return {
        cancel() {
            stop = true;
            cancelAnimationFrame(raf);
            c.clearRect(0, 0, 360, 220);
        },
        previewAt(ms) {
            stop = true;
            cancelAnimationFrame(raf);
            draw(ms);
        },
    };
};
