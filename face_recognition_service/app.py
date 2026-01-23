#!/usr/bin/env python3
"""
Face Recognition Service for Laberion Worker Management System
This service handles face enrollment and recognition for worker attendance
"""

import os
import pickle
import json
import numpy as np
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Tuple, Optional

from flask import Flask, request, jsonify
from flask_cors import CORS
import face_recognition
from PIL import Image
import cv2

app = Flask(__name__)
CORS(app)  # Enable CORS for all routes

# Configuration
CONFIG = {
    'KNOWN_FACES_FILE': 'known_faces.dat',
    'KNOWN_FACES_DIR': 'known_faces',
    'TEMP_DIR': 'temp_images',
    'FACE_MATCH_THRESHOLD': 0.6,  # Lower = more strict, Higher = more lenient
    'MAX_FACES_PER_WORKER': 10,
    'IMAGE_SIZE': (500, 500),  # Resize images for consistency
}

# Global variables to store face data in memory
known_face_encodings: List[np.ndarray] = []
known_face_ids: List[int] = []
known_face_metadata: Dict[int, Dict] = {}  # worker_id -> metadata

def init_directories():
    """Create necessary directories if they don't exist"""
    os.makedirs(CONFIG['KNOWN_FACES_DIR'], exist_ok=True)
    os.makedirs(CONFIG['TEMP_DIR'], exist_ok=True)

def load_known_faces():
    """Load known faces from disk into memory"""
    global known_face_encodings, known_face_ids, known_face_metadata
    
    known_faces_file = CONFIG['KNOWN_FACES_FILE']
    
    if os.path.exists(known_faces_file):
        try:
            with open(known_faces_file, 'rb') as f:
                data = pickle.load(f)
                known_face_encodings = data['encodings']
                known_face_ids = data['ids']
                known_face_metadata = data.get('metadata', {})
            
            print(f"✅ Loaded {len(known_face_ids)} known faces from {known_faces_file}")
            print(f"   Workers with faces: {len(set(known_face_ids))}")
            
        except Exception as e:
            print(f"❌ Error loading known faces: {e}")
            known_face_encodings = []
            known_face_ids = []
            known_face_metadata = {}
    else:
        print(f"ℹ️  No known faces file found at {known_faces_file}. Starting fresh.")
        known_face_encodings = []
        known_face_ids = []
        known_face_metadata = {}

def save_known_faces():
    """Save known faces to disk"""
    try:
        data = {
            'encodings': known_face_encodings,
            'ids': known_face_ids,
            'metadata': known_face_metadata,
            'saved_at': datetime.now().isoformat(),
            'version': '1.0'
        }
        
        with open(CONFIG['KNOWN_FACES_FILE'], 'wb') as f:
            pickle.dump(data, f)
        
        print(f"💾 Saved {len(known_face_ids)} face encodings to {CONFIG['KNOWN_FACES_FILE']}")
        return True
        
    except Exception as e:
        print(f"❌ Error saving known faces: {e}")
        return False

def preprocess_image(image_path: str) -> Optional[np.ndarray]:
    """
    Preprocess image: resize, convert to RGB, enhance if needed
    """
    try:
        # Load image
        image = face_recognition.load_image_file(image_path)
        
        # Convert BGR to RGB (face_recognition uses RGB)
        if len(image.shape) == 3 and image.shape[2] == 3:
            image = cv2.cvtColor(image, cv2.COLOR_BGR2RGB)
        
        # Resize if too large (for performance)
        height, width = image.shape[:2]
        if height > 1000 or width > 1000:
            scale = 1000 / max(height, width)
            new_height = int(height * scale)
            new_width = int(width * scale)
            image = cv2.resize(image, (new_width, new_height))
        
        return image
        
    except Exception as e:
        print(f"❌ Error preprocessing image: {e}")
        return None

def detect_and_encode_faces(image_path: str) -> Tuple[List[np.ndarray], List[Tuple]]:
    """
    Detect faces in image and return encodings and locations
    Returns: (encodings, face_locations)
    """
    try:
        # Preprocess image
        image = preprocess_image(image_path)
        if image is None:
            return [], []
        
        # Detect face locations
        face_locations = face_recognition.face_locations(
            image,
            model='hog',  # Use 'hog' for CPU, 'cnn' for GPU (more accurate but slower)
            number_of_times_to_upsample=1
        )
        
        if not face_locations:
            return [], []
        
        # Get face encodings
        face_encodings = face_recognition.face_encodings(
            image,
            face_locations,
            num_jitters=1,  # Number of times to re-sample the face
            model='small'   # Use 'small' for faster encoding
        )
        
        return face_encodings, face_locations
        
    except Exception as e:
        print(f"❌ Error detecting/encoding faces: {e}")
        return [], []

def find_best_match(face_encoding: np.ndarray, threshold: float = None) -> Tuple[Optional[int], float]:
    """
    Find the best matching worker for a face encoding
    Returns: (worker_id, confidence) or (None, 0.0) if no match
    """
    if threshold is None:
        threshold = CONFIG['FACE_MATCH_THRESHOLD']
    
    if not known_face_encodings:
        return None, 0.0
    
    # Calculate face distances to all known faces
    face_distances = face_recognition.face_distance(known_face_encodings, face_encoding)
    
    # Find the best match (smallest distance)
    best_match_index = np.argmin(face_distances)
    best_distance = face_distances[best_match_index]
    
    # Convert distance to confidence (0-1, higher is better)
    confidence = 1.0 - best_distance
    
    # Check if confidence meets threshold
    if confidence >= threshold:
        worker_id = known_face_ids[best_match_index]
        return worker_id, float(confidence)
    
    return None, float(confidence)

@app.route('/recognize', methods=['POST'])
def recognize():
    """
    Recognize a face from uploaded image
    Expects: multipart/form-data with 'image' field
    Returns: JSON with recognition results
    """
    try:
        # Check if image was uploaded
        if 'image' not in request.files:
            return jsonify({
                'success': False,
                'message': 'No image provided',
                'code': 'NO_IMAGE'
            }), 400
        
        image_file = request.files['image']
        
        # Validate file
        if image_file.filename == '':
            return jsonify({
                'success': False,
                'message': 'No image selected',
                'code': 'EMPTY_FILE'
            }), 400
        
        # Save temporary file
        temp_filename = f"temp_{datetime.now().timestamp()}_{image_file.filename}"
        temp_path = os.path.join(CONFIG['TEMP_DIR'], temp_filename)
        image_file.save(temp_path)
        
        # Detect and encode faces
        face_encodings, face_locations = detect_and_encode_faces(temp_path)
        
        # Clean up temp file
        try:
            os.remove(temp_path)
        except:
            pass
        
        # Check results
        if not face_encodings:
            return jsonify({
                'success': False,
                'message': 'No face detected in the image',
                'code': 'NO_FACE_DETECTED'
            }), 404
        
        if len(face_encodings) > 1:
            return jsonify({
                'success': False,
                'message': 'Multiple faces detected. Please ensure only one face is visible.',
                'code': 'MULTIPLE_FACES',
                'faces_detected': len(face_encodings)
            }), 400
        
        # Get the face encoding
        face_encoding = face_encodings[0]
        
        # Find best match
        worker_id, confidence = find_best_match(face_encoding)
        
        if worker_id is not None:
            # Get worker metadata
            metadata = known_face_metadata.get(worker_id, {})
            
            return jsonify({
                'success': True,
                'worker_id': worker_id,
                'confidence': confidence,
                'message': 'Face recognized successfully',
                'metadata': metadata,
                'face_location': face_locations[0] if face_locations else None
            })
        else:
            return jsonify({
                'success': False,
                'message': 'Face not recognized',
                'confidence': confidence,
                'code': 'FACE_NOT_RECOGNIZED',
                'threshold': CONFIG['FACE_MATCH_THRESHOLD']
            }), 404
            
    except Exception as e:
        print(f"❌ Recognition error: {e}")
        return jsonify({
            'success': False,
            'message': f'Internal server error: {str(e)}',
            'code': 'INTERNAL_ERROR'
        }), 500

@app.route('/enroll', methods=['POST'])
def enroll():
    """
    Enroll a new face for a worker
    Expects: multipart/form-data with 'image' and 'worker_id' fields
    Returns: JSON with enrollment results
    """
    try:
        # Check required fields
        if 'image' not in request.files:
            return jsonify({
                'success': False,
                'message': 'No image provided',
                'code': 'NO_IMAGE'
            }), 400
        
        worker_id = request.form.get('worker_id')
        if not worker_id:
            return jsonify({
                'success': False,
                'message': 'No worker ID provided',
                'code': 'NO_WORKER_ID'
            }), 400
        
        try:
            worker_id = int(worker_id)
        except ValueError:
            return jsonify({
                'success': False,
                'message': 'Invalid worker ID format',
                'code': 'INVALID_WORKER_ID'
            }), 400
        
        image_file = request.files['image']
        
        # Save temporary file
        temp_filename = f"enroll_{worker_id}_{datetime.now().timestamp()}.jpg"
        temp_path = os.path.join(CONFIG['TEMP_DIR'], temp_filename)
        image_file.save(temp_path)
        
        # Detect and encode faces
        face_encodings, face_locations = detect_and_encode_faces(temp_path)
        
        # Clean up temp file
        try:
            os.remove(temp_path)
        except:
            pass
        
        # Check results
        if not face_encodings:
            return jsonify({
                'success': False,
                'message': 'No face detected in the image',
                'code': 'NO_FACE_DETECTED'
            }), 400
        
        if len(face_encodings) > 1:
            return jsonify({
                'success': False,
                'message': 'Multiple faces detected. Please upload image with only one face.',
                'code': 'MULTIPLE_FACES'
            }), 400
        
        # Get the face encoding
        face_encoding = face_encodings[0]
        
        # Check if worker already has too many faces enrolled
        worker_face_count = known_face_ids.count(worker_id)
        if worker_face_count >= CONFIG['MAX_FACES_PER_WORKER']:
            return jsonify({
                'success': False,
                'message': f'Worker already has {worker_face_count} faces enrolled (max: {CONFIG["MAX_FACES_PER_WORKER"]})',
                'code': 'MAX_FACES_REACHED'
            }), 400
        
        # Add to known faces
        known_face_encodings.append(face_encoding)
        known_face_ids.append(worker_id)
        
        # Update metadata
        if worker_id not in known_face_metadata:
            known_face_metadata[worker_id] = {
                'first_enrolled': datetime.now().isoformat(),
                'last_enrolled': datetime.now().isoformat(),
                'total_faces': 1,
                'enrollment_dates': [datetime.now().isoformat()]
            }
        else:
            metadata = known_face_metadata[worker_id]
            metadata['last_enrolled'] = datetime.now().isoformat()
            metadata['total_faces'] = worker_face_count + 1
            metadata['enrollment_dates'].append(datetime.now().isoformat())
        
        # Save to disk
        save_known_faces()
        
        # Save original image to known_faces directory
        original_filename = f"worker_{worker_id}_{len(known_face_encodings)}.jpg"
        original_path = os.path.join(CONFIG['KNOWN_FACES_DIR'], original_filename)
        image_file.seek(0)  # Reset file pointer
        image_file.save(original_path)
        
        return jsonify({
            'success': True,
            'message': 'Face enrolled successfully',
            'worker_id': worker_id,
            'face_index': len(known_face_encodings) - 1,
            'total_faces_for_worker': worker_face_count + 1,
            'total_faces_in_system': len(known_face_encodings),
            'image_saved_as': original_filename
        })
        
    except Exception as e:
        print(f"❌ Enrollment error: {e}")
        return jsonify({
            'success': False,
            'message': f'Internal server error: {str(e)}',
            'code': 'INTERNAL_ERROR'
        }), 500

@app.route('/enroll_batch', methods=['POST'])
def enroll_batch():
    """
    Enroll multiple faces for a worker at once
    Expects: multipart/form-data with multiple 'images[]' and 'worker_id'
    """
    try:
        worker_id = request.form.get('worker_id')
        if not worker_id:
            return jsonify({
                'success': False,
                'message': 'No worker ID provided'
            }), 400
        
        worker_id = int(worker_id)
        
        images = request.files.getlist('images[]')
        if not images:
            return jsonify({
                'success': False,
                'message': 'No images provided'
            }), 400
        
        successful = 0
        failed = 0
        results = []
        
        for i, image_file in enumerate(images):
            # Save temporary file
            temp_filename = f"batch_{worker_id}_{i}_{datetime.now().timestamp()}.jpg"
            temp_path = os.path.join(CONFIG['TEMP_DIR'], temp_filename)
            image_file.save(temp_path)
            
            # Detect and encode
            face_encodings, _ = detect_and_encode_faces(temp_path)
            
            # Clean up
            try:
                os.remove(temp_path)
            except:
                pass
            
            if face_encodings and len(face_encodings) == 1:
                # Add to known faces
                known_face_encodings.append(face_encodings[0])
                known_face_ids.append(worker_id)
                successful += 1
                results.append({
                    'image_index': i,
                    'status': 'success',
                    'message': 'Face enrolled'
                })
            else:
                failed += 1
                results.append({
                    'image_index': i,
                    'status': 'failed',
                    'message': 'No face or multiple faces detected'
                })
        
        # Update metadata
        if successful > 0:
            if worker_id not in known_face_metadata:
                known_face_metadata[worker_id] = {
                    'first_enrolled': datetime.now().isoformat(),
                    'last_enrolled': datetime.now().isoformat(),
                    'total_faces': successful,
                    'enrollment_dates': [datetime.now().isoformat()]
                }
            else:
                metadata = known_face_metadata[worker_id]
                metadata['last_enrolled'] = datetime.now().isoformat()
                metadata['total_faces'] += successful
                metadata['enrollment_dates'].append(datetime.now().isoformat())
            
            # Save to disk
            save_known_faces()
        
        return jsonify({
            'success': True,
            'message': f'Batch enrollment completed: {successful} successful, {failed} failed',
            'worker_id': worker_id,
            'successful': successful,
            'failed': failed,
            'results': results,
            'total_faces_for_worker': known_face_ids.count(worker_id)
        })
        
    except Exception as e:
        print(f"❌ Batch enrollment error: {e}")
        return jsonify({
            'success': False,
            'message': f'Internal server error: {str(e)}'
        }), 500

@app.route('/worker/<int:worker_id>/faces', methods=['GET'])
def get_worker_faces(worker_id: int):
    """Get information about enrolled faces for a worker"""
    face_indices = [i for i, wid in enumerate(known_face_ids) if wid == worker_id]
    
    if not face_indices:
        return jsonify({
            'success': False,
            'message': f'No faces found for worker {worker_id}',
            'worker_id': worker_id
        }), 404
    
    metadata = known_face_metadata.get(worker_id, {})
    
    return jsonify({
        'success': True,
        'worker_id': worker_id,
        'face_count': len(face_indices),
        'face_indices': face_indices,
        'metadata': metadata,
        'first_face_index': face_indices[0] if face_indices else None,
        'last_face_index': face_indices[-1] if face_indices else None
    })

@app.route('/worker/<int:worker_id>/faces', methods=['DELETE'])
def delete_worker_faces(worker_id: int):
    """Delete all faces for a worker"""
    try:
        # Find indices to remove
        indices_to_remove = [i for i, wid in enumerate(known_face_ids) if wid == worker_id]
        
        if not indices_to_remove:
            return jsonify({
                'success': False,
                'message': f'No faces found for worker {worker_id}'
            }), 404
        
        # Remove in reverse order to maintain indices
        for index in sorted(indices_to_remove, reverse=True):
            known_face_encodings.pop(index)
            known_face_ids.pop(index)
        
        # Remove metadata
        if worker_id in known_face_metadata:
            del known_face_metadata[worker_id]
        
        # Save to disk
        save_known_faces()
        
        return jsonify({
            'success': True,
            'message': f'Deleted {len(indices_to_remove)} faces for worker {worker_id}',
            'worker_id': worker_id,
            'faces_deleted': len(indices_to_remove)
        })
        
    except Exception as e:
        print(f"❌ Delete faces error: {e}")
        return jsonify({
            'success': False,
            'message': f'Internal server error: {str(e)}'
        }), 500

@app.route('/status', methods=['GET'])
def status():
    """Get service status and statistics"""
    total_faces = len(known_face_encodings)
    unique_workers = len(set(known_face_ids)) if known_face_ids else 0
    
    # Calculate average faces per worker
    avg_faces_per_worker = total_faces / unique_workers if unique_workers > 0 else 0
    
    # Get worker with most faces
    worker_counts = {}
    for worker_id in known_face_ids:
        worker_counts[worker_id] = worker_counts.get(worker_id, 0) + 1
    
    most_faces_worker = max(worker_counts.items(), key=lambda x: x[1]) if worker_counts else (None, 0)
    
    return jsonify({
        'status': 'online',
        'timestamp': datetime.now().isoformat(),
        'service': 'Laberion Face Recognition',
        'version': '1.0.0',
        'statistics': {
            'total_faces': total_faces,
            'unique_workers': unique_workers,
            'average_faces_per_worker': round(avg_faces_per_worker, 2),
            'worker_with_most_faces': {
                'worker_id': most_faces_worker[0],
                'face_count': most_faces_worker[1]
            }
        },
        'configuration': {
            'face_match_threshold': CONFIG['FACE_MATCH_THRESHOLD'],
            'max_faces_per_worker': CONFIG['MAX_FACES_PER_WORKER'],
            'known_faces_file': CONFIG['KNOWN_FACES_FILE'],
            'known_faces_dir': CONFIG['KNOWN_FACES_DIR']
        },
        'memory_usage': {
            'face_encodings_size': f"{total_faces * 128 * 8 / 1024:.2f} KB",  # 128 floats * 8 bytes each
            'metadata_size': f"{len(str(known_face_metadata).encode('utf-8')) / 1024:.2f} KB"
        }
    })

@app.route('/config/threshold', methods=['POST'])
def update_threshold():
    """Update face match threshold"""
    try:
        data = request.get_json()
        if not data or 'threshold' not in data:
            return jsonify({
                'success': False,
                'message': 'No threshold provided'
            }), 400
        
        new_threshold = float(data['threshold'])
        
        # Validate threshold
        if new_threshold < 0.1 or new_threshold > 1.0:
            return jsonify({
                'success': False,
                'message': 'Threshold must be between 0.1 and 1.0'
            }), 400
        
        old_threshold = CONFIG['FACE_MATCH_THRESHOLD']
        CONFIG['FACE_MATCH_THRESHOLD'] = new_threshold
        
        return jsonify({
            'success': True,
            'message': 'Threshold updated successfully',
            'old_threshold': old_threshold,
            'new_threshold': new_threshold
        })
        
    except Exception as e:
        print(f"❌ Update threshold error: {e}")
        return jsonify({
            'success': False,
            'message': f'Internal server error: {str(e)}'
        }), 500

@app.route('/health', methods=['GET'])
def health():
    """Simple health check endpoint"""
    return jsonify({
        'status': 'healthy',
        'timestamp': datetime.now().isoformat(),
        'service': 'face_recognition'
    })

@app.route('/test', methods=['GET'])
def test():
    """Test endpoint to verify service is working"""
    return jsonify({
        'success': True,
        'message': 'Face recognition service is running',
        'timestamp': datetime.now().isoformat(),
        'endpoints': {
            'POST /recognize': 'Recognize face from image',
            'POST /enroll': 'Enroll new face',
            'POST /enroll_batch': 'Enroll multiple faces',
            'GET /worker/<id>/faces': 'Get worker face info',
            'DELETE /worker/<id>/faces': 'Delete worker faces',
            'GET /status': 'Get service status',
            'POST /config/threshold': 'Update match threshold',
            'GET /health': 'Health check',
            'GET /test': 'This test endpoint'
        }
    })

def main():
    """Main function to start the service"""
    print("=" * 60)
    print("🚀 Laberion Face Recognition Service")
    print("=" * 60)
    
    # Initialize directories
    init_directories()
    
    # Load known faces
    load_known_faces()
    
    # Get host and port from environment or use defaults
    host = os.environ.get('FACE_SERVICE_HOST', '0.0.0.0')
    port = int(os.environ.get('FACE_SERVICE_PORT', 5000))
    
    print(f"\n📡 Service starting on: http://{host}:{port}")
    print(f"📁 Known faces loaded: {len(known_face_encodings)}")
    print(f"👥 Unique workers: {len(set(known_face_ids))}")
    print(f"🎯 Match threshold: {CONFIG['FACE_MATCH_THRESHOLD']}")
    print("\n✅ Ready to accept requests!")
    print("=" * 60)
    
    # Start Flask app
    app.run(host=host, port=port, debug=False)

if __name__ == '__main__':
    main()