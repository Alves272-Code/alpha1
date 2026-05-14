function dashboard() {
    return {
        aba: window.abaInicial || 'pedidos',
        sidebar: false,
        largura: window.innerWidth,
        modalReabrir: false,
        reabrirId: null
    }
}

function galeriaManager() {
    return {
        dragover: false,
        previews: [],
        fileList: [],
        novaCapaIndex: null,
        draggedEl: null,
        
        dragStart(e) {
            this.draggedEl = e.target.closest('.gallery-thumb');
            if (this.draggedEl) {
                e.dataTransfer.effectAllowed = 'move';
                this.draggedEl.classList.add('sortable-drag');
            }
        },
        
        dragOver(e) {
            const target = e.target.closest('.gallery-thumb');
            if (target && target !== this.draggedEl) {
                const container = document.getElementById('sortable-gallery');
                const boxes = [...container.querySelectorAll('.gallery-thumb')];
                const targetIndex = boxes.indexOf(target);
                const draggedIndex = boxes.indexOf(this.draggedEl);
                
                if (targetIndex !== -1 && draggedIndex !== -1) {
                    const next = targetIndex + (e.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2 ? 1 : 0);
                    if (next > draggedIndex) {
                        container.insertBefore(this.draggedEl, boxes[next] || null);
                    } else {
                        container.insertBefore(this.draggedEl, target);
                    }
                }
            }
        },
        
        drop(e) {
            e.preventDefault();
            if (this.draggedEl) {
                this.draggedEl.classList.remove('sortable-drag');
                this.atualizarOrdem();
            }
        },
        
        dragEnd(e) {
            if (this.draggedEl) {
                this.draggedEl.classList.remove('sortable-drag');
                this.draggedEl = null;
            }
        },
        
        atualizarOrdem() {
            const container = document.getElementById('sortable-gallery');
            if (!container) return;
            const items = container.querySelectorAll('.gallery-thumb');
            const ids = Array.from(items).map(el => el.dataset.id).filter(id => id);
            this.$refs.imagensOrdemInput.value = ids.join(',');
        },
        
        handleDrop(e) {
            this.dragover = false;
            this.adicionarFicheiros(e.dataTransfer.files);
        },
        
        handleFiles(el) {
            this.adicionarFicheiros(el.files);
            el.value = '';
        },
        
        adicionarFicheiros(files) {
            Array.from(files).forEach(file => {
                if (!file.type.startsWith('image/')) {
                    alert('Apenas imagens são permitidas: ' + file.name);
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    alert('Arquivo muito grande (máx 5MB): ' + file.name);
                    return;
                }
                this.fileList.push(file);
                const reader = new FileReader();
                reader.onload = (e) => {
                    this.previews.push({ 
                        src: e.target.result, 
                        file: file,
                        name: file.name
                    });
                };
                reader.readAsDataURL(file);
            });
            this.atualizarFileInput();
        },
        
        removerPreview(index) {
            this.fileList.splice(index, 1);
            this.previews.splice(index, 1);
            if (this.novaCapaIndex === index) {
                this.novaCapaIndex = null;
                this.$refs.capaNovaIndex.value = '';
            } else if (this.novaCapaIndex > index) {
                this.novaCapaIndex--;
                this.$refs.capaNovaIndex.value = this.novaCapaIndex;
            }
            this.atualizarFileInput();
        },
        
        setCapaNova(index) {
            this.novaCapaIndex = index;
            this.$refs.capaNovaIndex.value = index;
            this.$refs.capaExistenteId.value = '';
        },
        
        setCapaExistente(imgId) {
            this.$refs.capaExistenteId.value = imgId;
            this.$refs.capaNovaIndex.value = '';
            this.novaCapaIndex = null;
        },
        
        atualizarFileInput() {
            const dt = new DataTransfer();
            this.fileList.forEach(f => dt.items.add(f));
            this.$refs.inputGaleria.files = dt.files;
        },
        
        prepararEnvio() {
            this.atualizarOrdem();
        }
    }
}

function chat(contactoId) {
    return {
        minimizado: false,
        enviando: false,
        scroll() {
            this.$nextTick(() => {
                let el = this.$refs.msgContainer;
                if(el) el.scrollTop = el.scrollHeight;
            });
        },
        async enviarMensagem() {
            this.enviando = true;
            let formData = new FormData(this.$el);
            formData.append('ajax', '1');
            try {
                let resp = await fetch('dashboard.php', { method: 'POST', body: formData, credentials: 'same-origin' });
                if (!resp.ok) throw new Error('Erro HTTP: ' + resp.status);
                const text = await resp.text();
                let data;
                try { 
                    data = JSON.parse(text); 
                } catch (e) { 
                    console.error('Resposta não JSON:', text); 
                    throw new Error('Resposta do servidor inválida'); 
                }
                if (data.erro) { 
                    alert(data.erro); 
                } else {
                    this.$refs.msgContainer.insertAdjacentHTML('beforeend', data.html);
                    this.scroll();
                    this.$refs.mensagemInput.value = '';
                    if (this.$refs.anexoComponent && this.$refs.anexoComponent.remover) {
                        this.$refs.anexoComponent.remover();
                    }
                }
            } catch (e) { 
                console.error(e); 
                alert('Erro de conexão.'); 
            }
            this.enviando = false;
        }
    }
}

function anexo() {
    return {
        ficheiro: null,
        preview: null,
        arrastando: false,
        remover() { 
            this.ficheiro = null; 
            this.preview = null; 
            if (this.$refs.inputFile) this.$refs.inputFile.value = ''; 
        },
        selecionar(e) {
            const f = e.target.files[0];
            if(!f) return;
            if(f.size > 5*1024*1024) { 
                alert('Máximo 5MB'); 
                e.target.value = ''; 
                return; 
            }
            this.ficheiro = f;
            if(f.type.startsWith('image/')) {
                let reader = new FileReader();
                reader.onload = (ev) => this.preview = ev.target.result;
                reader.readAsDataURL(f);
            } else {
                this.preview = null;
            }
        },
        largar(e) {
            this.arrastando = false;
            if(e.dataTransfer.files.length) {
                this.$refs.inputFile.files = e.dataTransfer.files;
                this.selecionar({ target: { files: e.dataTransfer.files } });
            }
        },
    }
}