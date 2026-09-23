// Precise vehicle-condition map. The paths share the source illustration's
// 560 x 879 coordinate system, so a click resolves to a named body part
// instead of an arbitrary point on the canvas.
function initDamageDiagram(prefix)
{
    const TYPES = {
        C: { label: 'Cut', color: '#dc2626' },
        D: { label: 'Dent', color: '#d97706' },
        S: { label: 'Scratch', color: '#2563eb' }
    };

    const PARTS = [
        { key: 'rear_bumper', label: 'Rear bumper', group: 'Rear', d: 'M164 40 Q280 30 396 40 L408 87 Q404 104 389 106 L171 106 Q156 103 152 87 Z', x: 280, y: 72 },
        { key: 'trunk', label: 'Trunk', group: 'Rear', d: 'M164 107 L396 107 L389 222 Q352 236 280 237 Q208 236 171 222 Z', x: 280, y: 166 },
        { key: 'rear_windshield', label: 'Rear windshield', group: 'Rear', d: 'M180 226 Q280 201 380 226 L382 319 Q280 346 178 319 Z', x: 280, y: 278 },
        { key: 'roof', label: 'Roof', group: 'Roof', d: 'M178 326 Q280 350 382 326 L382 548 Q280 570 178 548 Z', x: 280, y: 438 },
        { key: 'windshield', label: 'Windshield', group: 'Front', d: 'M178 554 Q280 578 382 554 L390 624 Q280 654 170 624 Z', x: 280, y: 603 },
        { key: 'bonnet', label: 'Bonnet', group: 'Front', d: 'M170 635 Q280 665 390 635 L397 702 L163 702 Z', x: 280, y: 670 },
        { key: 'front_bumper', label: 'Front bumper', group: 'Front', d: 'M164 705 L396 705 L401 728 Q414 746 411 775 Q407 797 390 800 L170 800 Q153 797 149 775 Q146 746 159 728 Z', x: 280, y: 756 },

        { key: 'rear_left_quarter_panel', label: 'Rear left quarter panel', group: 'Rear', d: 'M39 106 Q69 94 99 105 L126 122 L132 177 L119 178 Q107 153 80 153 L40 163 Z M40 255 L119 255 Q129 239 132 223 L132 280 L105 289 L40 276 Z', x: 92, y: 128 },
        { key: 'rear_right_quarter_panel', label: 'Rear right quarter panel', group: 'Rear', d: 'M521 106 Q491 94 461 105 L434 122 L428 177 L441 178 Q453 153 480 153 L520 163 Z M520 255 L441 255 Q431 239 428 223 L428 280 L455 289 L520 276 Z', x: 468, y: 128 },
        { key: 'rear_left_door', label: 'Rear left door', group: 'Side', d: 'M132 280 L158 282 Q174 300 182 328 L182 404 L132 405 Z', x: 155, y: 350 },
        { key: 'rear_right_door', label: 'Rear right door', group: 'Side', d: 'M428 280 L402 282 Q386 300 378 328 L378 404 L428 405 Z', x: 405, y: 350 },
        { key: 'front_left_door', label: 'Front left door', group: 'Side', d: 'M132 414 L182 414 L182 514 Q174 535 158 553 L132 553 Z', x: 155, y: 478 },
        { key: 'front_right_door', label: 'Front right door', group: 'Side', d: 'M428 414 L378 414 L378 514 Q386 535 402 553 L428 553 Z', x: 405, y: 478 },
        { key: 'front_left_fender', label: 'Front left fender', group: 'Front', d: 'M32 503 L96 504 Q119 515 132 554 L132 686 Q118 698 99 696 L99 677 Q118 667 118 642 L111 606 Q101 580 79 570 L32 563 Z', x: 72, y: 640 },
        { key: 'front_right_fender', label: 'Front right fender', group: 'Front', d: 'M528 503 L464 504 Q441 515 428 554 L428 686 Q442 698 461 696 L461 677 Q442 667 442 642 L449 606 Q459 580 481 570 L528 563 Z', x: 488, y: 640 },
        { key: 'front_left_mirror', label: 'Front left mirror', group: 'Front', d: 'M169 300 Q181 295 190 309 L193 329 Q188 342 176 339 L168 329 Z', x: 180, y: 320 },
        { key: 'front_right_mirror', label: 'Front right mirror', group: 'Front', d: 'M391 300 Q379 295 370 309 L367 329 Q372 342 384 339 L392 329 Z', x: 380, y: 320 },
        { key: 'rear_left_wheel', label: 'Rear left wheel', group: 'Rear', d: 'M64 164 A45 45 0 1 1 63.9 254 A45 45 0 1 1 64 164 Z', x: 64, y: 209 },
        { key: 'rear_right_wheel', label: 'Rear right wheel', group: 'Rear', d: 'M496 164 A45 45 0 1 1 495.9 254 A45 45 0 1 1 496 164 Z', x: 496, y: 209 },
        { key: 'front_left_wheel', label: 'Front left wheel', group: 'Front', d: 'M64 512 A45 45 0 1 1 63.9 602 A45 45 0 1 1 64 512 Z', x: 64, y: 557 },
        { key: 'front_right_wheel', label: 'Front right wheel', group: 'Front', d: 'M496 512 A45 45 0 1 1 495.9 602 A45 45 0 1 1 496 512 Z', x: 496, y: 557 }
    ];

    const el = id => document.getElementById(`${prefix}-${id}`);
    const map = el('damage-map');
    const stage = document.querySelector(`#${prefix}-damage-diagram-wrap .damage-image-stage`);
    const image = stage ? stage.querySelector('.damage-diagram-image') : null;
    const picker = el('damage-picker');
    const pickerTitle = el('damage-picker-title');
    const selectionList = el('damage-selection-list');
    const imageDataInput = el('damage-image-data');
    const marksInput = el('damage-marks-json');
    const form = stage ? stage.closest('form') : null;

    if (!map || !stage || !image || !picker || !pickerTitle || !selectionList || !imageDataInput || !marksInput)
    {
        return;
    }

    let selectedPart = null;
    let pendingPoint = null;
    let marks = [];

    function partByKey(key)
    {
        return PARTS.find(part => part.key === key);
    }

    function createSvgElement(name, attributes)
    {
        const node = document.createElementNS('http://www.w3.org/2000/svg', name);

        Object.entries(attributes).forEach(([attribute, value]) => node.setAttribute(attribute, value));

        return node;
    }

    function markForPart(partKey)
    {
        return marks.filter(mark => mark.part_key === partKey);
    }

    function renderMap()
    {
        map.replaceChildren();

        PARTS.forEach(part =>
        {
            const path = createSvgElement('path', {
                d: part.d,
                'data-part': part.key,
                class: `damage-region${markForPart(part.key).length ? ' selected' : ''}`,
                tabindex: '0',
                'aria-label': part.label
            });

            path.addEventListener('click', event =>
            {
                event.stopPropagation();
                openPicker(part, event.clientX, event.clientY);
            });

            path.addEventListener('keydown', event =>
            {
                if (event.key === 'Enter' || event.key === ' ')
                {
                    event.preventDefault();
                    const rect = path.getBoundingClientRect();
                    openPicker(part, rect.left + rect.width / 2, rect.top + rect.height / 2);
                }
            });

            map.appendChild(path);
        });
    }

    function openPicker(part, clientX, clientY)
    {
        selectedPart = part;
        pickerTitle.textContent = part.label;

        const mapRect = map.getBoundingClientRect();
        pendingPoint = {
            x: Math.min(560, Math.max(0, ((clientX - mapRect.left) / mapRect.width) * 560)),
            y: Math.min(879, Math.max(0, ((clientY - mapRect.top) / mapRect.height) * 879))
        };

        const stageRect = stage.getBoundingClientRect();
        const pickerWidth = 168;
        const pickerHeight = 82;
        const left = Math.min(Math.max(clientX - stageRect.left, pickerWidth / 2), stageRect.width - pickerWidth / 2);
        const top = Math.min(Math.max(clientY - stageRect.top, pickerHeight / 2), stageRect.height - pickerHeight / 2);

        picker.style.left = `${left}px`;
        picker.style.top = `${top}px`;
        picker.hidden = false;
    }

    function closePicker()
    {
        picker.hidden = true;
        selectedPart = null;
        pendingPoint = null;
    }

    function addMark(type)
    {
        if (!selectedPart || !TYPES[type])
        {
            return;
        }

        marks = marks.filter(mark => !(mark.part_key === selectedPart.key && mark.damage_type === type));
        marks.push({
            part_key: selectedPart.key,
            damage_type: type,
            x: pendingPoint ? pendingPoint.x : selectedPart.x,
            y: pendingPoint ? pendingPoint.y : selectedPart.y
        });

        renderMap();
        renderSelections();
        closePicker();
    }

    function removeMark(partKey, type)
    {
        marks = marks.filter(mark => !(mark.part_key === partKey && mark.damage_type === type));
        renderMap();
        renderSelections();
    }

    function renderSelections()
    {
        selectionList.replaceChildren();

        marks.forEach(mark =>
        {
            const part = partByKey(mark.part_key);
            const type = TYPES[mark.damage_type];

            if (!part || !type)
            {
                return;
            }

            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'damage-selection';
            item.style.setProperty('--damage-color', type.color);
            item.innerHTML = `<span>${part.label}</span><strong>${type.label}</strong><span aria-hidden="true">×</span>`;
            item.title = `Remove ${type.label.toLowerCase()} on ${part.label.toLowerCase()}`;
            item.addEventListener('click', () => removeMark(mark.part_key, mark.damage_type));
            selectionList.appendChild(item);
        });
    }

    function exportMarkedImage()
    {
        if (!image.complete || !image.naturalWidth)
        {
            return '';
        }

        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const context = canvas.getContext('2d');

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        marks.forEach(mark =>
        {
            const type = TYPES[mark.damage_type];
            const radius = 17;

            context.beginPath();
            context.arc(mark.x, mark.y, radius, 0, Math.PI * 2);
            context.fillStyle = type.color;
            context.fill();
            context.lineWidth = 2;
            context.strokeStyle = '#1c1a17';
            context.stroke();
            context.fillStyle = '#ffffff';
            context.font = '700 18px Arial, sans-serif';
            context.textAlign = 'center';
            context.textBaseline = 'middle';
            context.fillText(mark.damage_type, mark.x, mark.y + 1);
        });

        return canvas.toDataURL('image/png');
    }

    picker.querySelectorAll('button[data-type]').forEach(button =>
    {
        button.addEventListener('click', () => addMark(button.dataset.type));
    });

    document.addEventListener('click', event =>
    {
        if (!picker.hidden && !picker.contains(event.target) && !map.contains(event.target))
        {
            closePicker();
        }
    });

    if (form)
    {
        form.addEventListener('submit', () =>
        {
            imageDataInput.value = marks.length ? exportMarkedImage() : '';
            marksInput.value = JSON.stringify(marks);
        });
    }

    renderMap();
    renderSelections();
}
