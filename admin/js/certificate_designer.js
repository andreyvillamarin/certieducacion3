document.addEventListener('DOMContentLoaded', function() {
    const designerDataElement = document.getElementById('designer-data');
    if (!designerDataElement) {
        console.error('Elemento de datos del diseñador no encontrado.');
        return;
    }

    const savedTemplate = JSON.parse(designerDataElement.dataset.template);
    const defaultTemplate = JSON.parse(designerDataElement.dataset.defaultTemplate);
    const basePath = designerDataElement.dataset.basePath;
    const signaturePath = basePath + designerDataElement.dataset.signaturePath;

    const canvasContainer = document.getElementById('canvas-container');
    const a4_aspect_ratio = 297 / 210; // L / H
    const initialWidth = canvasContainer.clientWidth;
    const initialHeight = initialWidth / a4_aspect_ratio;

    const canvas = new fabric.Canvas('certificate-canvas', {
        width: initialWidth,
        height: initialHeight,
        backgroundColor: '#f0f0f0',
        preserveObjectStacking: true
    });

    function loadTemplate(templateData) {
        const data = JSON.parse(JSON.stringify(templateData));
        const backgroundData = data.backgroundImage;
        delete data.backgroundImage;

        canvas.clear();
        canvas.loadFromJSON(data, () => {
            const signaturePlaceholder = canvas.getObjects().find(obj => obj.data && obj.data.field === 'signature');
            if (signaturePlaceholder) {
                fabric.Image.fromURL(signaturePath, function(sigImg) {
                    sigImg.set({
                        left: signaturePlaceholder.left,
                        top: signaturePlaceholder.top,
                        width: signaturePlaceholder.getScaledWidth(),
                        height: signaturePlaceholder.getScaledHeight(),
                        data: signaturePlaceholder.data,
                        crossOrigin: 'anonymous'
                    });
                    canvas.remove(signaturePlaceholder);
                    canvas.add(sigImg);
                    canvas.renderAll();
                }, { crossOrigin: 'anonymous' });
            }
            
            if (backgroundData && backgroundData.src) {
                const backgroundUrl = basePath + backgroundData.src;
                fabric.Image.fromURL(backgroundUrl, function(bgImg) {
                    canvas.setBackgroundImage(bgImg, canvas.renderAll.bind(canvas), {
                        originX: 'left',
                        originY: 'top',
                        crossOrigin: 'anonymous'
                    });
                }, { crossOrigin: 'anonymous' });
            } else {
                canvas.renderAll();
            }
        });
    }

    function resizeCanvas() {
        const containerWidth = canvasContainer.clientWidth;
        const bgImage = canvas.backgroundImage;
        let scale;
        if (bgImage && bgImage.width > 0) {
            scale = containerWidth / bgImage.width;
            canvas.setDimensions({ width: containerWidth, height: bgImage.height * scale });
        } else {
            scale = containerWidth / 842; // Default A4 width in points
            canvas.setDimensions({ width: containerWidth, height: containerWidth / a4_aspect_ratio });
        }
        canvas.setZoom(scale);
        canvas.renderAll();
    }
    
    loadTemplate(Object.keys(savedTemplate).length > 1 ? savedTemplate : defaultTemplate);
    setTimeout(resizeCanvas, 300);
    window.addEventListener('resize', resizeCanvas);
    
    const textControls = document.getElementById('text-controls');
    const objectControls = document.getElementById('object-controls');
    const fontFamily = document.getElementById('font-family');
    const fontSize = document.getElementById('font-size');
    const fontColor = document.getElementById('font-color');
    const fontBold = document.getElementById('font-bold');
    const fontItalic = document.getElementById('font-italic');
    const fontUnderline = document.getElementById('font-underline');
    const alignLeft = document.getElementById('align-left');
    const alignCenter = document.getElementById('align-center');
    const alignRight = document.getElementById('align-right');
    const alignJustify = document.getElementById('align-justify');
    const alignmentButtons = [alignLeft, alignCenter, alignRight, alignJustify];
    const objectAlignCenterH = document.getElementById('object-align-center-h');
    const objectAlignCenterV = document.getElementById('object-align-center-v');

    function updateControls(target) {
        if (target) {
            objectControls.style.display = 'block';
        } else {
            objectControls.style.display = 'none';
        }

        if (target && target.isType('textbox')) {
            textControls.style.display = 'block';
            fontFamily.value = target.get('fontFamily') || 'Arial';
            fontSize.value = target.get('fontSize');
            fontColor.value = target.get('fill');
            fontBold.classList.toggle('active', target.get('fontWeight') === 'bold');
            fontItalic.classList.toggle('active', target.get('fontStyle') === 'italic');
            fontUnderline.classList.toggle('active', target.get('underline') === true);
            
            const currentAlign = target.get('textAlign') || 'left';
            alignmentButtons.forEach(btn => {
                btn.classList.remove('active');
                if (btn.id === `align-${currentAlign}`) {
                    btn.classList.add('active');
                }
            });
        } else {
            textControls.style.display = 'none';
        }
    }

    canvas.on({
        'selection:created': (e) => updateControls(e.selected[0]),
        'selection:updated': (e) => updateControls(e.selected[0]),
        'selection:cleared': () => updateControls(null)
    });

    fontFamily.addEventListener('change', (e) => { const obj = canvas.getActiveObject(); if (obj) { obj.set('fontFamily', e.target.value); canvas.renderAll(); } });
    fontSize.addEventListener('input', (e) => { const obj = canvas.getActiveObject(); if (obj) { obj.set('fontSize', parseInt(e.target.value, 10) || 12); canvas.renderAll(); } });
    fontColor.addEventListener('input', (e) => { const obj = canvas.getActiveObject(); if (obj) { obj.set('fill', e.target.value); canvas.renderAll(); } });
    fontBold.addEventListener('click', () => { const obj = canvas.getActiveObject(); if (obj) { obj.set('fontWeight', obj.get('fontWeight') === 'bold' ? 'normal' : 'bold'); fontBold.classList.toggle('active'); canvas.renderAll(); } });
    fontItalic.addEventListener('click', () => { const obj = canvas.getActiveObject(); if (obj) { obj.set('fontStyle', obj.get('fontStyle') === 'italic' ? 'normal' : 'italic'); fontItalic.classList.toggle('active'); canvas.renderAll(); } });
    fontUnderline.addEventListener('click', () => { const obj = canvas.getActiveObject(); if (obj) { obj.set('underline', !obj.get('underline')); fontUnderline.classList.toggle('active'); canvas.renderAll(); } });

    alignmentButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const align = btn.id.replace('align-', '');
            const activeObject = canvas.getActiveObject();
            if (activeObject && activeObject.isType('textbox')) {
                activeObject.set('textAlign', align);
                alignmentButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                canvas.renderAll();
            }
        });
    });

    objectAlignCenterH.addEventListener('click', () => {
        const activeObject = canvas.getActiveObject();
        if (activeObject) { activeObject.centerH(); canvas.renderAll(); }
    });

    objectAlignCenterV.addEventListener('click', () => {
        const activeObject = canvas.getActiveObject();
        if (activeObject) { activeObject.centerV(); canvas.renderAll(); }
    });

    document.getElementById('background-uploader').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(f) {
            fabric.Image.fromURL(f.target.result, function(img) {
                img.set({ crossOrigin: 'anonymous' });
                canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas), { originX: 'left', originY: 'top' });
                resizeCanvas();
            });
        };
        reader.readAsDataURL(file);
    });

    document.getElementById('save-template').addEventListener('click', function() {
        const button = this;
        button.disabled = true;
        const formData = new FormData();
        
        const jsonOutput = { version: '5.3.1', objects: [] };
        canvas.getObjects().forEach(obj => {
            jsonOutput.objects.push(obj.toObject(['data']));
        });
        if (canvas.backgroundImage) {
            if (canvas.backgroundImage.getSrc().startsWith('data:')) {
                jsonOutput.backgroundImage = { type: 'image', src: '' }; 
            } else {
                jsonOutput.backgroundImage = canvas.backgroundImage.toObject(['data']);
            }
        }
        
        formData.append('template_json', JSON.stringify(jsonOutput));
        
        const signatureFile = document.getElementById('signature-uploader').files[0];
        if (signatureFile) { formData.append('signature_image', signatureFile); }
        
        const backgroundFile = document.getElementById('background-uploader').files[0];
        if (backgroundFile) { formData.append('background_image', backgroundFile); }
        
        fetch('ajax_save_template.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                alert('¡Plantilla guardada con éxito!');
                if (data.new_template_json) { 
                    designerDataElement.dataset.template = JSON.stringify(data.new_template_json);
                    loadTemplate(data.new_template_json);
                }
            } else {
                alert('Error al guardar: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error de conexión.');
        })
        .finally(() => { button.disabled = false; });
    });

    document.getElementById('reset-template').addEventListener('click', function() {
        if (confirm('¿Restaurar la plantilla original? Perderás los cambios no guardados.')) {
            loadTemplate(defaultTemplate);
            alert('Plantilla restaurada. Haz clic en "Guardar Cambios" para confirmar.');
        }
    });
});