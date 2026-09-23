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
        { key: 'front_bumper', label: 'Front bumper', group: 'Front', d: 'M164 40 Q280 30 396 40 L408 87 Q404 104 389 106 L171 106 Q156 103 152 87 Z', x: 280, y: 72 },
        { key: 'bonnet', label: 'Bonnet', group: 'Front', d: 'M164 107 L396 107 L389 222 Q352 236 280 237 Q208 236 171 222 Z', x: 280, y: 166 },
        { key: 'windshield', label: 'Windshield', group: 'Front', d: 'M180 226 Q280 201 380 226 L382 319 Q280 346 178 319 Z', x: 280, y: 278 },
        { key: 'roof', label: 'Roof', group: 'Roof', d: 'M178 326 Q280 350 382 326 L382 548 Q280 570 178 548 Z', x: 280, y: 438 },
        { key: 'rear_windshield', label: 'Rear windshield', group: 'Rear', d: 'M178 554 Q280 578 382 554 L390 624 Q280 654 170 624 Z', x: 280, y: 603 },
        { key: 'trunk', label: 'Trunk', group: 'Rear', d: 'M170 635 Q280 665 390 635 L397 702 L163 702 Z', x: 280, y: 670 },
        { key: 'rear_bumper', label: 'Rear bumper', group: 'Rear', d: 'M164 705 L396 705 L401 728 Q414 746 411 775 Q407 797 390 800 L170 800 Q153 797 149 775 Q146 746 159 728 Z', x: 280, y: 756 },
        { key: 'rear_left_tail_light', label: 'Rear left tail light', group: 'Rear', d: 'M164 676 Q178 665 197 671 L202 701 L166 701 Z', x: 183, y: 687 },
        { key: 'rear_right_tail_light', label: 'Rear right tail light', group: 'Rear', d: 'M396 676 Q382 665 363 671 L358 701 L394 701 Z', x: 377, y: 687 },
        { key: 'front_grille', label: 'Front grille', group: 'Front', d: 'M190 49 Q190 45 195 45 H365 Q370 45 370 50 V59 Q370 64 365 64 H195 Q190 64 190 59 Z', x: 280, y: 54 },
        { key: 'front_left_headlight', label: 'Front left headlight', group: 'Front', d: 'M166 112 L205 112 L205 139 L166 139 Z', x: 185, y: 125 },
        { key: 'front_right_headlight', label: 'Front right headlight', group: 'Front', d: 'M394 112 L355 112 L355 139 L394 139 Z', x: 375, y: 125 },

        { key: 'front_left_fender', label: 'Front left fender', group: 'Front', d: 'M39 106 Q69 94 99 105 L126 122 L132 177 L119 178 Q107 153 80 153 L40 163 Z M40 255 L119 255 Q129 239 132 223 L132 280 L105 279 L40 276 Z', x: 92, y: 128 },
        { key: 'front_right_fender', label: 'Front right fender', group: 'Front', d: 'M521 106 Q491 94 461 105 L434 122 L428 177 L441 178 Q453 153 480 153 L520 163 Z M520 255 L441 255 Q431 239 428 223 L428 280 L455 279 L520 276 Z', x: 468, y: 128 },
        // Door-metal rectangles follow the green reference boxes in the
        // source artwork's native 560 x 879 coordinate system.
        { key: 'front_left_door_metal', label: 'Front left door metal', group: 'Side', d: 'M54 282 H106 V396 H54 Z', x: 80, y: 339 },
        { key: 'front_right_door_metal', label: 'Front right door metal', group: 'Side', d: 'M506 282 H454 V396 H506 Z', x: 480, y: 339 },
        { key: 'rear_left_door_metal', label: 'Rear left door metal', group: 'Side', d: 'M54 405 H106 V493 H54 Z', x: 80, y: 449 },
        { key: 'rear_right_door_metal', label: 'Rear right door metal', group: 'Side', d: 'M506 405 H454 V493 H506 Z', x: 480, y: 449 },
        // Door glass — traced against the real window shading, then
        // deliberately inset ~10px on every side. The true window fills
        // most of the door's visible height (confirmed by a full grid
        // scan: a pixel-accurate trace left door-metal clickable only in
        // a thin strip near the bottom — every click anywhere near or on
        // the window would land on glass, making metal feel unreachable
        // even though it technically existed). The inset gives metal a
        // real, comfortable frame all the way around the window instead
        // of ceding it almost the entire door.
        { key: 'front_left_door_glass', label: 'Front left door glass', group: 'Side', d: 'M144 328 L151 327 L172 360 L172 383 L150 390 L144 386 Z', x: 155, y: 360 },
        { key: 'front_right_door_glass', label: 'Front right door glass', group: 'Side', d: 'M416 328 L409 327 L388 360 L388 383 L410 390 L416 386 Z', x: 405, y: 360 },
        { key: 'rear_left_door_glass', label: 'Rear left door glass', group: 'Side', d: 'M145 428 L172 428 L172 478 L148 492 L145 485 Z', x: 155, y: 460 },
        { key: 'rear_right_door_glass', label: 'Rear right door glass', group: 'Side', d: 'M415 428 L388 428 L388 478 L412 492 L415 485 Z', x: 405, y: 460 },
        { key: 'rear_left_quarter_panel', label: 'Rear left quarter panel', group: 'Rear', d: 'M32 503 L96 504 Q119 515 132 554 L132 686 Q118 698 99 696 L99 677 Q118 667 118 642 L111 606 Q101 580 79 570 L32 563 Z', x: 72, y: 640 },
        { key: 'rear_right_quarter_panel', label: 'Rear right quarter panel', group: 'Rear', d: 'M528 503 L464 504 Q441 515 428 554 L428 686 Q442 698 461 696 L461 677 Q442 667 442 642 L449 606 Q459 580 481 570 L528 563 Z', x: 488, y: 640 },
        // Mirrors — tightened to a small ellipse matching the actual oval
        // decoration in the artwork (~23x23px). The previous paddle shape
        // was ~24x40px, tall enough to swallow real door-metal area both
        // above and below the real mirror, so clicks near the top of the
        // front door kept resolving to "mirror" instead of the door.
        { key: 'front_left_mirror', label: 'Front left mirror', group: 'Front', d: 'M171 320 A10 11 0 1 1 191 319.9 A10 11 0 1 1 171 320 Z', x: 181, y: 320 },
        { key: 'front_right_mirror', label: 'Front right mirror', group: 'Front', d: 'M369 320 A10 11 0 1 1 389 319.9 A10 11 0 1 1 369 320 Z', x: 379, y: 320 },
        // Side skirts / rocker panels follow the green reference strips:
        // one long body section below the doors, between the wheel arches.
        { key: 'left_side_skirt', label: 'Left side skirt', group: 'Side', d: 'M34 258 H51 V503 H34 Z', x: 42.5, y: 380 },
        { key: 'right_side_skirt', label: 'Right side skirt', group: 'Side', d: 'M526 258 H509 V503 H526 Z', x: 517.5, y: 380 },
        { key: 'fuel_door', label: 'Fuel door', group: 'Side', d: 'M105 266 Q116 260 126 266 L126 287 Q116 293 105 287 Z', x: 116, y: 276 },
        { key: 'front_left_wheel', label: 'Front left wheel', group: 'Front', d: 'M64 161 A48 48 0 1 1 63.9 257 A48 48 0 1 1 64 161 Z', x: 64, y: 209 },
        { key: 'front_right_wheel', label: 'Front right wheel', group: 'Front', d: 'M496 161 A48 48 0 1 1 495.9 257 A48 48 0 1 1 496 161 Z', x: 496, y: 209 },
        // Rear wheel circles are inset to the visible tire ring so their
        // selectable boundary does not spill into the adjacent body panel.
        { key: 'rear_left_wheel', label: 'Rear left wheel', group: 'Rear', d: 'M55 508 A45 45 0 1 1 54.9 598 A45 45 0 1 1 55 508 Z', x: 55, y: 553 },
        { key: 'rear_right_wheel', label: 'Rear right wheel', group: 'Rear', d: 'M505 508 A45 45 0 1 1 504.9 598 A45 45 0 1 1 505 508 Z', x: 505, y: 553 },

        // Door handles — small ovals traced from the artwork, listed last
        // so they render on top of (and take click priority over) the
        // door-metal regions they visually sit inside.
        { key: 'front_left_door_handle', label: 'Front left door handle', group: 'Side', d: 'M112,367.5 A9,14.5 0 1 1 130,367.4 A9,14.5 0 1 1 112,367.5 Z', x: 121, y: 367.5 },
        { key: 'front_right_door_handle', label: 'Front right door handle', group: 'Side', d: 'M430,367.5 A9,14.5 0 1 1 448,367.4 A9,14.5 0 1 1 430,367.5 Z', x: 439, y: 367.5 },
        { key: 'rear_left_door_handle', label: 'Rear left door handle', group: 'Side', d: 'M112.5,495 A8.5,12 0 1 1 129.5,494.9 A8.5,12 0 1 1 112.5,495 Z', x: 121, y: 495 },
        { key: 'rear_right_door_handle', label: 'Rear right door handle', group: 'Side', d: 'M430.5,495 A8.5,12 0 1 1 447.5,494.9 A8.5,12 0 1 1 430.5,495 Z', x: 439, y: 495 }
    ];

    const el = id => document.getElementById(`${prefix}-${id}`);
    const wrap = el('damage-diagram-wrap');
    const map = el('damage-map');
    const stage = wrap ? wrap.querySelector('.damage-image-stage') : null;
    const image = stage ? stage.querySelector('.damage-diagram-image') : null;
    const picker = el('damage-picker');
    const pickerTitle = el('damage-picker-title');
    const selectionList = el('damage-selection-list');
    const imageDataInput = el('damage-image-data');
    const marksInput = el('damage-marks-json');
    const form = stage ? stage.closest('form') : null;

    if (!wrap || !map || !stage || !image || !picker || !pickerTitle || !selectionList || !imageDataInput || !marksInput)
    {
        return;
    }

    let selectedPart = null;
    let pendingPoint = null;

    // Seeded when this is an edit rather than a first-time add — see
    // damage_intake_block() in JobCardIntakeExtras.php. Malformed or
    // missing seed data just means "nothing to pre-fill", not an error.
    let marks = [];

    try
    {
        const seeded = JSON.parse(wrap.dataset.existingMarks || '[]');

        if (Array.isArray(seeded))
        {
            marks = seeded.filter(mark => mark
                && typeof mark.part_key === 'string'
                && typeof mark.damage_type === 'string'
                && typeof mark.x === 'number'
                && typeof mark.y === 'number');
        }
    }
    catch (error)
    {
        marks = [];
    }

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
